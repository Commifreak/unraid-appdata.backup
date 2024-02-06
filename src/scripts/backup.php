<?php

/**
 * This file handles the actual backup
 */

use unraid\plugins\AppdataBackup\ABHelper;
use unraid\plugins\AppdataBackup\ABSettings;

require_once("/usr/local/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php");
require_once dirname(__DIR__) . '/include/ABHelper.php';

//set_error_handler("unraid\plugins\AppdataBackup\ABHelper::errorHandler");

/**
 * Helper for later renaming of the backup folder to suffix -failed
 */
$backupStarted = new DateTime();


if (ABHelper::scriptRunning()) {
    ABHelper::notify("Appdata Backup", "Still running", "There is something running already.");
    exit;
}

if (file_exists(ABSettings::$tempFolder . '/' . ABSettings::$stateFileAbort)) {
    unlink(ABSettings::$tempFolder . '/' . ABSettings::$stateFileAbort);
}

if (file_exists(ABSettings::$tempFolder)) {
    exec("rm " . ABSettings::$tempFolder . '/*.log');
} // Creation of tempFolder is handled by backupLog

file_put_contents(ABSettings::$tempFolder . '/' . ABSettings::$stateFileScriptRunning, getmypid());

ABHelper::backupLog("👋 WELCOME TO APPDATA.BACKUP!! :D");
$unraidVersion           = parse_ini_file('/etc/unraid-version');
$emhttpPluginVersionPath = '/usr/local/emhttp/plugins/' . ABSettings::$appName . '/version';
$pluginVersion           = file_exists($emhttpPluginVersionPath) ? file_get_contents($emhttpPluginVersionPath) : null;
ABHelper::backupLog("plugin-version: " . $pluginVersion, ABHelper::LOGLEVEL_DEBUG);
ABHelper::backupLog("unraid-version: " . print_r($unraidVersion, true), ABHelper::LOGLEVEL_DEBUG);

/**
 * Some basic checks
 */
if (!ABHelper::isArrayOnline()) {
    ABHelper::backupLog("It doesn't appear that the array is running!", ABHelper::LOGLEVEL_ERR);
    goto end;
}

if (!file_exists(ABSettings::getConfigPath())) {
    ABHelper::backupLog("There is no configfile... Hmm...", ABHelper::LOGLEVEL_ERR);
    goto end;
}

$abSettings = new ABSettings();

if (empty($abSettings->destination)) {
    ABHelper::backupLog("Destination is not set!", ABHelper::LOGLEVEL_ERR);
    goto end;
}

if (!file_exists($abSettings->destination) || !is_writable($abSettings->destination)) {
    ABHelper::backupLog("Destination is unavailable or not writeable!", ABHelper::LOGLEVEL_ERR);
    goto end;
}

ABHelper::backupLog("Backing up from: " . implode(', ', $abSettings->allowedSources));

/**
 * At this point, we have something to work with.
 * Patch the destination for further usage
 */
$abDestination = rtrim($abSettings->destination, '/') . '/ab_' . date("Ymd_His");

ABHelper::backupLog("Backing up to: " . $abDestination);

if (!mkdir($abDestination)) {
    ABHelper::backupLog("Cannot create destination folder!", ABHelper::LOGLEVEL_ERR);
    goto end;
}

ABHelper::handlePrePostScript($abSettings->preRunScript, 'pre-run', $abDestination);
if (ABHelper::abortRequested()) {
    goto abort;
}


$dockerClient     = new DockerClient();
$dockerContainers = $dockerClient->getDockerContainers();

ABHelper::backupLog("Containers: " . print_r($dockerContainers, true), ABHelper::LOGLEVEL_DEBUG);


if (empty($dockerContainers)) {
    ABHelper::backupLog("There are no docker containers to back up!", ABHelper::LOGLEVEL_WARN);
    goto continuationForAll;
}

// Sort containers
$sortedStartContainers = ABHelper::sortContainers($dockerContainers, $abSettings->containerOrder);
$sortedStopContainers  = ABHelper::sortContainers($dockerContainers, $abSettings->containerOrder, true);

if (empty($sortedStopContainers)) {
    ABHelper::backupLog("There are no docker containers (after sorting) to back up!", ABHelper::LOGLEVEL_WARN);
    goto continuationForAll;
}

$alSortedContainers = array_column($sortedStopContainers, 'Name');
natsort($alSortedContainers);

ABHelper::backupLog("Selected containers: " . implode(', ', $alSortedContainers));

ABHelper::backupLog("Sorted Stop : " . implode(", ", array_column($sortedStopContainers, 'Name')), ABHelper::LOGLEVEL_DEBUG);
ABHelper::backupLog("Sorted Start: " . implode(", ", array_column($sortedStartContainers, 'Name')), ABHelper::LOGLEVEL_DEBUG);

ABHelper::backupLog("Saving container XML files...");
foreach (glob("/boot/config/plugins/dockerMan/templates-user/*") as $xmlFile) {
    copy($xmlFile, $abDestination . '/' . basename($xmlFile));
}

if (ABHelper::abortRequested()) {
    goto abort;
}

/**
 * Array of Container names, needing an update
 */
$dockerUpdateList = [''];

ABHelper::backupLog("Starting Docker auto-update check...", ABHelper::LOGLEVEL_DEBUG);
foreach ($sortedStopContainers as $container) {
    if ($container['isGroup']) {
        continue;
    }
    $containerSettings = $abSettings->getContainerSpecificSettings($container['Name']);
    if ($containerSettings['updateContainer'] == 'yes') {

        if (!isset($allInfo)) {
            ABHelper::backupLog("Requesting docker template meta...", ABHelper::LOGLEVEL_DEBUG);
            $dockerTemplates = new \DockerTemplates();
            $allInfo         = $dockerTemplates->getAllInfo(true, true);
            ABHelper::backupLog(var_export($allInfo, true), ABHelper::LOGLEVEL_DEBUG);
        }

        if (isset($allInfo[$container['Name']]) && ($allInfo[$container['Name']]['updated'] ?? 'true') == 'false') { # string 'false' = Update available!
            ABHelper::backupLog("Auto-Update for '{$container['Name']}' is enabled and update is available! Schedule update after backup...");
            $dockerUpdateList[] = $container['Name'];
        } else {
            ABHelper::backupLog("Auto-Update for '{$container['Name']}' is enabled but no update is available.");
        }
    }
    if (ABHelper::abortRequested()) {
        goto abort;
    }
}
ABHelper::backupLog("Docker update check finished!", ABHelper::LOGLEVEL_DEBUG);
ABHelper::backupLog("Planned container updates: " . implode(", ", $dockerUpdateList), ABHelper::LOGLEVEL_DEBUG);

ABHelper::handlePrePostScript($abSettings->preBackupScript, 'pre-backup', $abDestination);

ABHelper::doBackupMethod($abSettings->backupMethod);


continuationForAll:

/**
 * FlashBackup
 */
if ($abSettings->flashBackup == 'yes') {
    ABHelper::backupLog("Backing up the flash drive.");
    $docroot = '/usr/local/emhttp';
    $script  = $docroot . '/webGui/scripts/flash_backup';
    if (!file_exists($script)) {
        ABHelper::backupLog("The flash backup script is not available!", ABHelper::LOGLEVEL_ERR);
    } else {
        $output = null;
        exec($script . " " . ABSettings::$externalCmdPidCapture, $output);
        ABHelper::backupLog("flash backup returned: " . implode(", ", $output), ABHelper::LOGLEVEL_DEBUG);
        if (empty($output[0])) {
            ABHelper::backupLog("Flash backup failed: no answer from script!", ABHelper::LOGLEVEL_ERR);
        } else {
            if (!copy($docroot . '/' . $output[0], $abDestination . '/' . $output[0])) {
                ABHelper::backupLog("Copying flash backup to destination failed!", ABHelper::LOGLEVEL_ERR);
            } else {
                ABHelper::backupLog("Flash backup created!");
                if (!empty($abSettings->flashBackupCopy)) {
                    ABHelper::backupLog("Copying the flash backup to '{$abSettings->flashBackupCopy}' as well...");
                    if (!copy($docroot . '/' . $output[0], $abSettings->flashBackupCopy . '/' . $output[0])) {
                        ABHelper::backupLog("Copying the flash backup to '{$abSettings->flashBackupCopy}' FAILED!", ABHelper::LOGLEVEL_ERR);
                    }
                }
                // Following is from Download.php
                if ($backup = readlink($docroot . '/' . $output[0])) {
                    unlink($backup);
                }
                @unlink($docroot . '/' . $output[0]);
            }
        }
    }

}

if (ABHelper::abortRequested()) {
    goto abort;
}

if ($abSettings->backupVMMeta == 'yes') {

    if (!file_exists(ABSettings::$qemuFolder)) {
        ABHelper::backupLog("VM meta should be backed up but VM manager is disabled!", ABHelper::LOGLEVEL_WARN);
    } else {
        ABHelper::backupLog("VM meta backup enabled! Backing up...");

        $output = $resultcode = null;
        exec("tar -czf " . escapeshellarg($abDestination . '/vm_meta.tgz') . " " . ABSettings::$qemuFolder . '/ ' . ABSettings::$externalCmdPidCapture, $output, $resultcode);
        ABHelper::backupLog("tar return: $resultcode and output: " . print_r($output), ABHelper::LOGLEVEL_DEBUG);
        if ($resultcode != 0) {
            ABHelper::backupLog("Error while backing up VM XMLs. Please see debug log!", ABHelper::LOGLEVEL_ERR);
        } else {
            ABHelper::backupLog("Done!");
        }
    }
}

if (ABHelper::abortRequested()) {
    goto abort;
}


if (!empty($abSettings->includeFiles)) {
    ABHelper::backupLog("Include files is NOT empty:" . PHP_EOL . print_r($abSettings->includeFiles, true), ABHelper::LOGLEVEL_DEBUG);
    $extrasChecked = [];
    foreach ($abSettings->includeFiles as $extra) {
        $extra = trim($extra);
        if (!empty($extra) && file_exists($extra)) {
            if (is_link($extra)) {
                ABHelper::backupLog("Specified extra file/folder '$extra' is a symlink. Will convert it to its real path!", ABHelper::LOGLEVEL_WARN);
                $extra = readlink($extra); // file_exists checks symlinks for target existence, so at this point, we know, the symlink exists!
            }
            $extrasChecked[] = $extra;
        } else {
            ABHelper::backupLog("Specified extra file/folder '$extra' is empty or does not exist!", ABHelper::LOGLEVEL_ERR);
        }
    }

    if (empty($extrasChecked)) {
        ABHelper::backupLog("The tested extra files list is empty! Skipping extra files", ABHelper::LOGLEVEL_WARN);
    } else {
        ABHelper::backupLog("Extra files to backup: " . implode(', ', $extrasChecked), ABHelper::LOGLEVEL_DEBUG);
        $tarOptions = ['-c', '-P'];

        $destination = $abDestination . '/extra_files.tar';

        switch ($abSettings->compression) {
            case 'yes':
                $tarOptions[] = '-z'; // GZip
                $destination  .= '.gz';
                break;
            case 'yesMulticore':
                $tarOptions[] = '-I zstdmt'; // zst multicore
                $destination  .= '.zst';
                break;
        }
        $tarOptions[] = '-f ' . escapeshellarg($destination); // Destination file
        ABHelper::backupLog("Target archive: " . $destination, ABHelper::LOGLEVEL_DEBUG);

        foreach ($extrasChecked as $extraChecked) {
            $tarOptions[] = escapeshellarg($extraChecked);
        }

        $finalTarOptions = implode(" ", $tarOptions);

        ABHelper::backupLog("Generated tar command: " . $finalTarOptions, ABHelper::LOGLEVEL_DEBUG);
        ABHelper::backupLog("Backing up extra files...");

        $output = $resultcode = null;
        exec("tar " . $finalTarOptions . " 2>&1 " . ABSettings::$externalCmdPidCapture, $output, $resultcode);
        ABHelper::backupLog("Tar out: " . implode('; ', $output), ABHelper::LOGLEVEL_DEBUG);

        if ($resultcode > 0) {
            ABHelper::backupLog("tar creation failed! Tar said: " . implode('; ', $output), ABHelper::LOGLEVEL_ERR);
        } else {
            ABHelper::backupLog("Backup created without issues");
        }
    }
}


end:

if (ABHelper::$errorOccured) {
    ABHelper::backupLog("An error occurred during backup! RETENTION WILL NOT BE CHECKED! Please review the log. If you need further assistance, ask in the support forum.", ABHelper::LOGLEVEL_WARN);
} else {
    ABHelper::backupLog("Checking retention...");
    if (empty($abSettings->keepMinBackups) && empty($abSettings->deleteBackupsOlderThan)) {
        ABHelper::backupLog("BOTH retention settings are disabled!", ABHelper::LOGLEVEL_WARN);
    } else { // Retention enabled
        $keepMinBackupsNum = empty($abSettings->keepMinBackups) ? 0 : $abSettings->keepMinBackups;
        $curBackupsState   = array_reverse(glob(rtrim($abSettings->destination, '/') . '/ab_*'));// glob return sorted by name. Without naming, thats the oldest first, newest at the end

        $toKeep = array_slice($curBackupsState, 0, $keepMinBackupsNum);
        ABHelper::backupLog("toKeep after slicing:" . PHP_EOL . print_r($toKeep, true), ABHelper::LOGLEVEL_DEBUG);

        if (!empty($abSettings->deleteBackupsOlderThan)) {
            $nowDate = new DateTime();
            $nowDate->modify('-' . $abSettings->deleteBackupsOlderThan . ' days');
            ABHelper::backupLog("Delete backups older than " . $nowDate->format("Ymd_His"), ABHelper::LOGLEVEL_DEBUG);

            foreach ($curBackupsState as $backupItem) {
                $correctedItem = array_reverse(explode("/", $backupItem))[0];
                $backupDate    = date_create_from_format("??_Ymd_His", $correctedItem);
                if (!$backupDate) {
                    ABHelper::backupLog("Cannot create date from " . $correctedItem, ABHelper::LOGLEVEL_DEBUG);
                    $toKeep[] = $backupItem; // Keep the errornous object - Better safe than sorry.
                    continue;
                }
                if ($backupDate >= $nowDate && !in_array($backupItem, $toKeep)) {
                    ABHelper::backupLog("Keeping " . $backupItem, ABHelper::LOGLEVEL_DEBUG);
                    $toKeep[] = $backupItem;
                } else {
                    ABHelper::backupLog("Discarding $backupItem, because its newer or already in toKeep", ABHelper::LOGLEVEL_DEBUG);
                }
            }
        }

        $toDelete = array_diff($curBackupsState, $toKeep);
        ABHelper::backupLog("Resulting toKeep: " . implode(', ', $toKeep), ABHelper::LOGLEVEL_DEBUG);
        ABHelper::backupLog("Resulting deletion list: " . implode(', ', $toDelete), ABHelper::LOGLEVEL_DEBUG);

        foreach ($toDelete as $deleteBackupPath) {
            ABHelper::backupLog("Delete old backup: " . $deleteBackupPath);
            exec("rm -rf " . escapeshellarg($deleteBackupPath));
        }

    }
}

if (ABHelper::abortRequested()) {
    goto abort;
}

abort:
ABHelper::setCurrentContainerName(null);
if (ABHelper::abortRequested()) {
    ABHelper::$errorOccured = true;
    ABHelper::backupLog("Backup cancelled! Executing final things. You will be left behind with the current state!", ABHelper::LOGLEVEL_WARN);
}

ABHelper::backupLog("DONE! Thanks for using this plugin and have a safe day ;)");
ABHelper::backupLog("❤️");

sleep(1); # In some cases a backup could create two notifications in a row, Unraid discards the latter then, so sleep 1 second

if (!ABHelper::$errorOccured && $abSettings->successLogWanted == 'yes') {
    $backupEnded    = new DateTime();
    $diff           = $backupStarted->diff($backupEnded);
    $backupDuration = $diff->h . "h, " . $diff->i . "m";
    ABHelper::notify("Appdata Backup", "Backup done [$backupDuration]!", "The backup was successful and took $backupDuration!");
}

if (!empty($abDestination)) {
    copy(ABSettings::$tempFolder . '/' . ABSettings::$logfile, $abDestination . '/backup.log');
    copy(ABSettings::getConfigPath(), $abDestination . '/' . ABSettings::$settingsFile);
    if (ABHelper::$errorOccured) {
        copy(ABSettings::$tempFolder . '/' . ABSettings::$debugLogFile, $abDestination . '/backup.debug.log');
        rename($abDestination, $abDestination . '-failed');
    }
}
if (file_exists(ABSettings::$tempFolder . '/' . ABSettings::$stateFileAbort)) {
    unlink(ABSettings::$tempFolder . '/' . ABSettings::$stateFileAbort);
}
unlink(ABSettings::$tempFolder . '/' . ABSettings::$stateFileScriptRunning);

ABHelper::handlePrePostScript($abSettings->postRunScript, 'post-run', $abDestination, (ABHelper::$errorOccured ? 'false' : 'true'));

exit(ABHelper::$errorOccured ? 1 : 0);