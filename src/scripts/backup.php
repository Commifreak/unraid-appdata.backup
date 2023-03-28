<?php

/**
 * This file handles the actual backup
 */

use unraid\plugins\AppdataBackup\ABHelper;
use unraid\plugins\AppdataBackup\ABSettings;

require_once("/usr/local/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php");
require_once __DIR__ . '/../include/ABHelper.php';

/**
 * Helper for later renaming of the backup folder to suffix -failed
 */
$errorOccured = false;


if (ABHelper::scriptRunning()) {
    ABHelper::notify("Still running", "There is something running already.");
    exit;
}

if (file_exists(ABSettings::$tempFolder . '/' . ABSettings::$stateFileAbort)) {
    unlink(ABSettings::$tempFolder . '/' . ABSettings::$stateFileAbort);
}

if (file_exists(ABSettings::$tempFolder)) {
    exec("rm " . ABSettings::$tempFolder . '/*.log');
} // Creation of tempFolder is handled by backupLog

ABHelper::backupLog("👋 WELCOME TO APPDATA.BACKUP!! :D");

$unraidVersion = parse_ini_file('/etc/unraid-version');
ABHelper::backupLog("unraid-version: " . print_r($unraidVersion, true), ABHelper::LOGLEVEL_DEBUG);

file_put_contents(ABSettings::$tempFolder . '/' . ABSettings::$stateFileScriptRunning, getmypid());

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

ABHelper::backupLog("Config:" . PHP_EOL . print_r($abSettings, true), ABHelper::LOGLEVEL_DEBUG);

if (empty($abSettings->destination)) {
    ABHelper::backupLog("Destination is not set!", ABHelper::LOGLEVEL_ERR);
    goto end;
}

if (!file_exists($abSettings->destination) || !is_writable($abSettings->destination)) {
    ABHelper::backupLog("Destination is available or not writeable!", ABHelper::LOGLEVEL_ERR);
    goto end;
}

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

ABHelper::handlePrePostScript($abSettings->preRunScript);
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

ABHelper::backupLog("Sorted Stop : " . implode(", ", array_column($sortedStopContainers, 'Name')), ABHelper::LOGLEVEL_DEBUG);
ABHelper::backupLog("Sorted Start: " . implode(", ", array_column($sortedStartContainers, 'Name')), ABHelper::LOGLEVEL_DEBUG);

ABHelper::backupLog("Saving container XML files...");
foreach ($sortedStopContainers as $container) {
    ABHelper::backupLog("Container Settings for " . $container['Name'] . ": " . print_r($abSettings->getContainerSpecificSettings($container['Name']), true), ABHelper::LOGLEVEL_DEBUG);

    $xmlPath = "/boot/config/plugins/dockerMan/templates-user/my-{$container['Name']}.xml";
    if (file_exists($xmlPath)) {
        copy($xmlPath, $abDestination . '/' . basename($xmlPath));
    } else {
        ABHelper::backupLog("XML file for {$container['Name']} was not found!", ABHelper::LOGLEVEL_WARN);
    }

}

if (ABHelper::abortRequested()) {
    goto abort;
}


if ($abSettings->backupMethod == 'stopAll') {
    ABHelper::backupLog("Method: Stop all container before continuing.");
    foreach ($sortedStopContainers as $container) {
        ABHelper::stopContainer($container);

        if (ABHelper::abortRequested()) {
            goto abort;
        }
    }
    ABHelper::handlePrePostScript($abSettings->preBackupScript);

    if (ABHelper::abortRequested()) {
        goto abort;
    }
} else {
    ABHelper::backupLog("Method: Stop/Backup/Start");
    ABHelper::handlePrePostScript($abSettings->preBackupScript);

    if (ABHelper::abortRequested()) {
        goto abort;
    }

    foreach ($sortedStartContainers as $container) {
        ABHelper::stopContainer($container);

        if (ABHelper::abortRequested()) {
            goto abort;
        }

        if (!ABHelper::backupContainer($container, $abDestination)) {
            $errorOccured = true;
        }

        if (ABHelper::abortRequested()) {
            goto abort;
        }

        ABHelper::startContainer($container);

        if (ABHelper::abortRequested()) {
            goto abort;
        }
    }

    goto continuationForAll;
}

ABHelper::backupLog("Starting backup for containers");
foreach ($sortedStartContainers as $container) {
    if (!ABHelper::backupContainer($container, $abDestination)) {
        $errorOccured = true;
    }

    if (ABHelper::abortRequested()) {
        goto abort;
    }

}

ABHelper::handlePrePostScript($abSettings->postBackupScript);

if (ABHelper::abortRequested()) {
    goto abort;
}

ABHelper::backupLog("Set containers to previous state");
foreach ($sortedStartContainers as $container) {
    ABHelper::startContainer($container);

    if (ABHelper::abortRequested()) {
        goto abort;
    }
}


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
        exec($script, $output);
        ABHelper::backupLog("flash backup returned: " . implode(", ", $output), ABHelper::LOGLEVEL_DEBUG);
        if (empty($output[0])) {
            ABHelper::backupLog("Flash backup failed: no answer from script!", ABHelper::LOGLEVEL_ERR);
        } else {
            if (!copy($docroot . '/' . $output[0], $abDestination . '/' . $output[0])) {
                ABHelper::backupLog("Copying flash backup to destination failed!", ABHelper::LOGLEVEL_ERR);
            } else {
                ABHelper::backupLog("Flash backup created!");
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

        exec("tar -czf " . escapeshellarg($abDestination . '/vm_meta.tgz') . " " . ABSettings::$qemuFolder . '/', $output, $resultcode);
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

foreach ($sortedStopContainers as $container) {
    $containerSettings = $abSettings->getContainerSpecificSettings($container['Name']);
    if ($containerSettings['updateContainer'] == 'yes') {
        ABHelper::backupLog("Auto-Update for '{$container['Name']}' is enabled - checking for update...");
        $dockerUpdate    = new \DockerUpdate();
        $dockerTemplates = new \DockerTemplates();
        ABHelper::backupLog("downloadTemplates", ABHelper::LOGLEVEL_DEBUG);
        $dockerTemplates->downloadTemplates();
        ABHelper::backupLog("reloadUpdateStatus", ABHelper::LOGLEVEL_DEBUG);
        $dockerUpdate->reloadUpdateStatus($container['Image']);
        ABHelper::backupLog("getUpdateStatus", ABHelper::LOGLEVEL_DEBUG);
        $updateStatus = $dockerUpdate->getUpdateStatus($container['Image']);
        ABHelper::backupLog(print_r($updateStatus, true), ABHelper::LOGLEVEL_DEBUG);

        if ($updateStatus === false) {
            ABHelper::backupLog("Update available! Installing...");
            exec('/usr/local/emhttp/plugins/dynamix.docker.manager/scripts/update_container ' . escapeshellarg($container['Name']));
            ABHelper::backupLog("Update finished (hopefully).");
        } else {
            ABHelper::backupLog("No update available.");
        }
    }
    if (ABHelper::abortRequested()) {
        goto abort;
    }
}


if (!empty($abSettings->includeFiles)) {
    ABHelper::backupLog("Include files is NOT empty:" . PHP_EOL . print_r($abSettings->includeFiles, true), ABHelper::LOGLEVEL_DEBUG);
    $extras        = $excludes = explode("\r\n", $abSettings->includeFiles);
    $extrasChecked = [];
    foreach ($extras as $extra) {
        $extra = trim($extra);
        if (!empty($extra) && file_exists($extra)) {
            $extrasChecked[] = $extra;
        } else {
            ABHelper::backupLog("Specified extra file/folder '$extra' is empty or does not exist!", ABHelper::LOGLEVEL_WARN);
        }
    }

    if (empty($extrasChecked)) {
        ABHelper::backupLog("The tested extra files list is empty! Skipping extra files", ABHelper::LOGLEVEL_WARN);
    } else {
        ABHelper::backupLog("Extra files to backup: " . implode(', ', $extrasChecked), ABHelper::LOGLEVEL_DEBUG);
        $tarOptions = ['-c'];

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

        exec("tar " . $finalTarOptions . " 2>&1", $output, $resultcode);
        ABHelper::backupLog("Tar out: " . implode('; ', $output), ABHelper::LOGLEVEL_DEBUG);

        if ($resultcode > 0) {
            ABHelper::backupLog("tar creation failed! More output available inside debuglog, maybe.", ABHelper::LOGLEVEL_ERR);
        } else {
            ABHelper::backupLog("Backup created without issues");
        }
    }
}


end:

if ($errorOccured) {
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
                    ABHelper::backupLog("Cannot create date from " . $correctedItem, ABHelper::LOGLEVEL_ERR);
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

ABHelper::handlePrePostScript($abSettings->postRunScript);

abort:
if (ABHelper::abortRequested()) {
    $errorOccured = true;
    ABHelper::backupLog("Backup cancelled! Executing final things. You will be left behind with the current state!", ABHelper::LOGLEVEL_WARN);
}

ABHelper::backupLog("DONE! Thanks for using this plugin and have a safe day ;)");
ABHelper::backupLog("❤️");

if (!empty($abDestination)) {
    if ($errorOccured) {
        exec('rm -rf ' . escapeshellarg($abDestination));
    } else {
        copy(ABSettings::$tempFolder . '/' . ABSettings::$logfile, $abDestination . '/backup.log');
        copy(ABSettings::getConfigPath(), $abDestination . '/' . ABSettings::$settingsFile);
    }
}
if (file_exists(ABSettings::$tempFolder . '/' . ABSettings::$stateFileAbort)) {
    unlink(ABSettings::$tempFolder . '/' . ABSettings::$stateFileAbort);
}
unlink(ABSettings::$tempFolder . '/' . ABSettings::$stateFileScriptRunning);
