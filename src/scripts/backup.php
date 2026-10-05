<?php

/**
 * This file handles the actual backup
 */

use unraid\plugins\AppdataBackup\ABHelper;
use unraid\plugins\AppdataBackup\ABIntegrity;
use unraid\plugins\AppdataBackup\ABSettings;
use unraid\plugins\AppdataBackup\ABStatus;
use unraid\plugins\AppdataBackup\ABSnapshot;
use unraid\plugins\AppdataBackup\ABSteps;

// CLI only: nginx runs any .php under the plugin folder for a logged-in GET.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once("/usr/local/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php");
require_once dirname(__DIR__) . '/include/ABHelper.php';
require_once dirname(__DIR__) . '/include/ABStatus.php';

set_error_handler("unraid\plugins\AppdataBackup\ABHelper::errorHandler");

// From cron (see ABSettings::checkCron): 'scheduled' waits for a running job, 'extra' runs the extra schedule
$runArgs = array_slice($argv, 1);
if (in_array('extra', $runArgs, true)) {
    ABHelper::$runLabel = 'Extra schedule'; // names this run in every notification, "Still running" included
}

if (!ABHelper::claimRun(in_array('scheduled', $runArgs, true))) {
    exit;
}

/**
 * Helper for later renaming of the backup folder to suffix -failed
 */
$backupStarted = new DateTime(); // after claimRun(): a scheduled run's wait is not part of its duration

ABHelper::backupLog("👋 WELCOME TO APPDATA.BACKUP!! :D");
$unraidVersion           = parse_ini_file('/etc/unraid-version');
$emhttpPluginVersionPath = '/usr/local/emhttp/plugins/' . ABSettings::$appName . '/version';
$pluginVersion           = file_exists($emhttpPluginVersionPath) ? file_get_contents($emhttpPluginVersionPath) : null;
ABHelper::backupLog("plugin-version: " . $pluginVersion, ABHelper::LOGLEVEL_DEBUG);
ABHelper::backupLog(ABHelper::dump('unraid-version', $unraidVersion), ABHelper::LOGLEVEL_DEBUG);

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

// Retention never deletes either schedule's destination, even one picked inside the other and named like a set
$abDestinations = array_filter(array_map(fn($path) => $path === '' ? false : realpath($path), [$abSettings->destination, $abSettings->extraDestination]));

if (in_array('extra', $runArgs, true)) {
    ABHelper::backupLog("Running the extra schedule: only its chosen containers, into its own destination.");
    if ($abSettings->extraSchedule !== 'yes') {
        ABHelper::backupLog("The extra schedule is turned off (Settings, Use an extra schedule?).", ABHelper::LOGLEVEL_ERR);
        goto end;
    }
    $extraReal = $abSettings->extraDestination === '' ? false : realpath($abSettings->extraDestination); // realpath('') is the working directory
    if (rtrim($abSettings->extraDestination, '/') === rtrim($abSettings->destination, '/') || ($extraReal !== false && $extraReal === realpath($abSettings->destination))) {
        ABHelper::backupLog("The extra schedule needs its own destination, not the main one, so its retention cannot delete full backups!", ABHelper::LOGLEVEL_ERR);
        goto end;
    }
    $extraSummary = $abSettings->scheduleSummary();
    ABHelper::backupLog($extraSummary['line']);
    ABHelper::backupLog(ABHelper::dump('Extra schedule settings', $extraSummary['settings']), ABHelper::LOGLEVEL_DEBUG);
    ABHelper::backupLog(ABHelper::dump("Extra schedule's own container settings", $extraSummary['overrides']), ABHelper::LOGLEVEL_DEBUG);
    $abSettings = $abSettings->forSchedule('extra');
    ABHelper::$targetLogLevel = $abSettings->notification; // this run's own notification level; loading the settings set the Settings tab's
}

if (empty($abSettings->destination)) {
    ABHelper::backupLog("Destination is not set!", ABHelper::LOGLEVEL_ERR);
    goto end;
}

ABSteps::plan($abSettings);
ABSteps::start('Preparing');

$abDestination = rtrim($abSettings->destination, '/') . '/ab_' . date("Ymd_His");

ABHelper::handlePrePostScript($abSettings->preRunScript, 'pre-run', $abDestination);

if (!file_exists($abSettings->destination) || !is_writable($abSettings->destination)) {
    ABHelper::backupLog("Destination is unavailable or not writeable! Did you create the destination folder?", ABHelper::LOGLEVEL_ERR);
    goto end;
}

ABHelper::backupLog("Backing up from: " . implode(', ', $abSettings->allowedSources));

/**
 * At this point, we have something to work with.
 * Patch the destination for further usage
 */
ABHelper::backupLog("Backing up to: " . $abDestination);

if (!mkdir($abDestination)) {
    ABHelper::backupLog("Cannot create destination folder!", ABHelper::LOGLEVEL_ERR);
    goto end;
}

if (ABHelper::abortRequested()) {
    goto abort;
}


$dockerClient     = new DockerClient();
$allContainers    = $dockerClient->getDockerContainers();
$dockerContainers = $abSettings->scheduleContainers($allContainers);
$missing          = $abSettings->scheduleMissing($allContainers);
if ($missing) {
    ABHelper::backupLog("Included in the extra schedule but not found (renamed or removed?): " . implode(', ', $missing), ABHelper::LOGLEVEL_WARN);
}

ABHelper::backupLog(ABHelper::dump('Containers', array_column($dockerContainers ?: [], null, 'Name')), ABHelper::LOGLEVEL_DEBUG);


// Sort containers
$sortedStartContainers = ABHelper::sortContainers($dockerContainers ?: [], $abSettings->containerOrder);
$sortedStopContainers  = ABHelper::sortContainers($dockerContainers ?: [], $abSettings->containerOrder, true);

if (empty($sortedStopContainers)) {
    if ($abSettings->schedule === 'extra') {
        // An empty set would count as a good one, and retention would delete the real extra backups
        ABHelper::backupLog("None of the extra schedule's containers can be backed up: they are gone or set to skip!", ABHelper::LOGLEVEL_ERR);
        ABHelper::$errorOccured = true;
        goto end;
    }
    ABHelper::backupLog(empty($dockerContainers) ? "There are no docker containers to back up!" : "There are no docker containers (after sorting) to back up!", ABHelper::LOGLEVEL_WARN);
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
$dockerUpdateList = [];

ABHelper::backupLog("Starting Docker auto-update check...", ABHelper::LOGLEVEL_DEBUG);
foreach ($dockerContainers as $container) { // Use unraids docker container list for update checking!
    $containerSettings = $abSettings->getContainerSpecificSettings($container['Name']);

    /**
     * Log container specific settings one time
     */
    ABHelper::backupLog(ABHelper::dump($container['Name'] . ' specific settings', $containerSettings), ABHelper::LOGLEVEL_DEBUG);

    if ($containerSettings['skip'] == 'no' && $containerSettings['updateContainer'] == 'yes') {

        if (!isset($allInfo)) {
            ABHelper::backupLog("Requesting docker template meta...", ABHelper::LOGLEVEL_DEBUG);
            $dockerTemplates = new \DockerTemplates();
            $allInfo         = $dockerTemplates->getAllInfo(true, true);
            ABHelper::backupLog(ABHelper::dump('Docker template info', $allInfo), ABHelper::LOGLEVEL_DEBUG);
        }

        if (isset($allInfo[$container['Name']]) && ($allInfo[$container['Name']]['updated'] ?? 'true') == 'false') { # string 'false' = Update available!
            ABHelper::backupLog("Auto-Update for '{$container['Name']}' is enabled and update is available! Scheduling update after backup...");
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

$preBackupRet = ABHelper::handlePrePostScript($abSettings->preBackupScript, 'pre-backup', $abDestination);

if ($preBackupRet === 2) {
    ABHelper::backupLog("preBackup script decided to skip backup.");
} else {
    ABHelper::doBackupMethod($abSettings->backupMethod);
}


continuationForAll:

if (ABHelper::abortRequested()) {
    goto abort;
}

/**
 * FlashBackup
 */
if ($abSettings->flashBackup == 'yes') {
    ABSteps::start('Backing up the flash drive');
    if (!ABHelper::backupFlash($abDestination)) {
        ABHelper::$errorOccured = true;
    }
}

if (ABHelper::abortRequested()) {
    goto abort;
}

if ($abSettings->backupVMMeta == 'yes') {
    ABSteps::start('Backing up VM meta');

    if (!file_exists(ABSettings::$qemuFolder)) {
        ABHelper::backupLog("VM meta should be backed up but VM manager is disabled!", ABHelper::LOGLEVEL_WARN);
    } else {
        $output = $resultcode = null;
        // -C / stores the same relative names restore.php expects, without tar's leading-slash warning
        exec("tar -czf " . escapeshellarg($abDestination . '/vm_meta.tgz') . " -C / " . escapeshellarg(ltrim(ABSettings::$qemuFolder, '/') . '/') . " 2>&1 " . ABSettings::$externalCmdPidCapture, $output, $resultcode);
        ABHelper::backupLog(ABHelper::dump("tar return: $resultcode, output", $output), ABHelper::LOGLEVEL_DEBUG);
        if ($resultcode != 0) {
            ABHelper::backupLog("Error while backing up VM XMLs! Tar said: " . implode('; ', $output), ABHelper::LOGLEVEL_ERR);
        } else {
            ABHelper::backupLog("VM meta backed up.");
        }
    }
}

if (ABHelper::abortRequested()) {
    goto abort;
}


if (!empty($abSettings->includeFiles)) {
    ABSteps::start('Backing up extra files');
    ABHelper::backupLog(ABHelper::dump('Include files', $abSettings->includeFiles), ABHelper::LOGLEVEL_DEBUG);
    $extrasChecked = [];
    foreach ($abSettings->includeFiles as $extra) {
        $extra = $path = trim($extra);
        if (is_link($path)) {
            ABHelper::backupLog("Specified extra file/folder '$extra' is a symlink. Will convert it to its real path!", ABHelper::LOGLEVEL_WARN);
            $path = realpath($path); // readlink() stops after one link and keeps relative targets relative
        }
        // Checked after resolving: realpath() returns false if the target vanished since is_link()
        if (!empty($path) && file_exists($path)) {
            $extrasChecked[] = $path;
        } else {
            ABHelper::backupLog("Specified extra file/folder '$extra' is empty or does not exist!", ABHelper::LOGLEVEL_ERR);
        }
    }

    if (empty($extrasChecked)) {
        ABHelper::backupLog("The tested extra files list is empty! Skipping extra files", ABHelper::LOGLEVEL_WARN);
    } else {
        ABHelper::backupLog("Extra files to back up: " . implode(', ', $extrasChecked), ABHelper::LOGLEVEL_DEBUG);

        $tarExcludes = [];
        if (!empty($abSettings->globalExclusions)) {
            ABHelper::backupLog(ABHelper::dump('Global excludes', $abSettings->globalExclusions), ABHelper::LOGLEVEL_DEBUG);
            foreach ($abSettings->globalExclusions as $globalExclusion) {
                $tarExcludes[] = '--exclude ' . escapeshellarg($globalExclusion);
            }
        }

        $tarOptions = array_merge($tarExcludes, ['-c', '-P']);    // Add excludes to the beginning - https://unix.stackexchange.com/a/33334

        if ($abSettings->ignoreExclusionCase == 'yes') {
            $tarOptions[] = '--ignore-case';
        }

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

if (empty($abDestination) || !is_dir($abDestination)) {
    ABHelper::$errorOccured = true; // an early exit made no backup set, so nothing below may treat the run as clean
} elseif (($setFiles = glob(ABHelper::globQuote($abDestination) . '/*')) === false) {
    ABHelper::backupLog("Cannot list $abDestination, so it cannot be flushed to disk!", ABHelper::LOGLEVEL_ERR);
    ABHelper::$errorOccured = true;
} elseif (!ABHelper::$errorOccured) {
    ABSteps::start('Writing checksums');
    ABIntegrity::writeChecksums($abDestination, $setFiles);
    // Retention deletes older sets, so this one goes to disk first. File by file: sync -f does not reach the disks through /mnt/user.
    ABHelper::backupLog("Flushing the backup to disk...");
    $output = $resultcode = null;
    exec('sync ' . implode(' ', array_map('escapeshellarg', array_merge($setFiles, array_filter([$abDestination . '/' . ABIntegrity::FILE], 'is_file'), [$abDestination]))) . ' 2>&1', $output, $resultcode);
    if ($resultcode != 0) {
        ABHelper::backupLog("Flushing the backup to disk failed! sync said: " . implode('; ', $output), ABHelper::LOGLEVEL_ERR);
        ABHelper::$errorOccured = true;
    }
}

// An abort during the checksums or the flush must not let retention delete older sets
if (ABHelper::abortRequested()) {
    goto abort;
}

ABSteps::start('Retention');
if (ABHelper::$errorOccured) {
    ABHelper::backupLog("An error occurred during backup! RETENTION WILL NOT BE CHECKED! Please review the log. If you need further assistance, ask in the support forum.", ABHelper::LOGLEVEL_WARN);
} else {
    if (empty($abSettings->keepMinBackups) && empty($abSettings->deleteBackupsOlderThan)) {
        ABHelper::backupLog("BOTH retention settings are disabled!", ABHelper::LOGLEVEL_WARN);
    } else { // Retention enabled
        $keepMinBackupsNum = empty($abSettings->keepMinBackups) ? 0 : $abSettings->keepMinBackups;
        // glob sorts by name, so oldest first. Only real set names: another ab_* folder here (e.g. the extra schedule's destination) is not a backup.
        $curBackupsState   = array_values(array_filter(array_reverse(glob(ABHelper::globQuote(rtrim($abSettings->destination, '/')) . '/ab_*')), fn($backupItem) => preg_match(ABStatus::SET_PATTERN, basename($backupItem)) && !in_array(realpath($backupItem), $abDestinations, true)));

        // Only finished, successful sets count towards the minimum. This run's set gets its backup.log at the end.
        $goodBackups = array_values(array_filter($curBackupsState, fn($backupItem) => $backupItem === $abDestination || (!str_ends_with($backupItem, '-failed') && file_exists($backupItem . '/backup.log'))));

        $toKeep = array_slice($goodBackups, 0, $keepMinBackupsNum);
        ABHelper::backupLog(ABHelper::dump('toKeep after slicing', $toKeep), ABHelper::LOGLEVEL_DEBUG);

        if (!empty($abSettings->deleteBackupsOlderThan)) {
            $nowDate = new DateTime();
            $nowDate->modify('-' . $abSettings->deleteBackupsOlderThan . ' days');
            ABHelper::backupLog("Delete backups older than " . $nowDate->format("Ymd_His"), ABHelper::LOGLEVEL_DEBUG);

            foreach ($curBackupsState as $backupItem) {
                $correctedItem = preg_replace('/-failed$/', '', array_reverse(explode("/", $backupItem))[0]); // failed sets age out like the others
                $backupDate    = date_create_from_format("??_Ymd_His", $correctedItem);
                if (!$backupDate) {
                    ABHelper::backupLog("Cannot create date from " . $correctedItem, ABHelper::LOGLEVEL_DEBUG);
                    $toKeep[] = $backupItem; // Keep the errornous object - Better safe than sorry.
                    continue;
                }
                if (in_array($backupItem, $toKeep)) {
                    ABHelper::backupLog("Keeping $backupItem, because it is within the minimum number of backups", ABHelper::LOGLEVEL_DEBUG);
                } elseif ($backupDate >= $nowDate) {
                    ABHelper::backupLog("Keeping " . $backupItem, ABHelper::LOGLEVEL_DEBUG);
                    $toKeep[] = $backupItem;
                } else {
                    ABHelper::backupLog("Discarding $backupItem, because it is older than the cutoff", ABHelper::LOGLEVEL_DEBUG);
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

abort:
ABHelper::setCurrentContainerName(null);
ABSteps::start('Finishing');
ABSnapshot::destroyAll(); // an aborted run can still hold snapshots
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

if (!empty($abDestination) && is_dir($abDestination)) {
    copy(ABSettings::$tempFolder . '/' . ABSettings::$logfile, $abDestination . '/backup.log');
    copy(ABSettings::getConfigPath(), $abDestination . '/' . ABSettings::$settingsFile);
    if (ABHelper::$errorOccured) {
        copy(ABSettings::$tempFolder . '/' . ABSettings::$debugLogFile, $abDestination . '/backup.debug.log');
        rename($abDestination, $abDestination . '-failed');
        $abDestination = $abDestination . '-failed';
    }

    /**
     * Adjusting backup destination permissions (for this run)
     */
    exec("chown -R nobody:users " . escapeshellarg($abDestination));
    exec("chmod -R u=rw,g=r,o=- " . escapeshellarg($abDestination));
    exec("chmod u=rwx,g=rx,o=- " . escapeshellarg($abDestination));

    ABStatus::saveSummary($abSettings, time() - $backupStarted->getTimestamp());
}

ABHelper::handlePrePostScript($abSettings->postRunScript, 'post-run', $abDestination ?? 'false', (ABHelper::$errorOccured ? 'false' : 'true'));

foreach ([ABSettings::$stateFileAbort, ABSettings::$stateFileStep] as $stateFile) {
    if (file_exists(ABSettings::$tempFolder . '/' . $stateFile)) {
        unlink(ABSettings::$tempFolder . '/' . $stateFile);
    }
}
unlink(ABSettings::$tempFolder . '/' . ABSettings::$stateFileScriptRunning);

exit(ABHelper::$errorOccured ? 1 : 0);
