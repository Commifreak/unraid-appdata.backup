<?php

/**
 * This file handles the actual backup
 */

use unraid\plugins\AppdataBackup\ABHelper;
use unraid\plugins\AppdataBackup\ABIntegrity;
use unraid\plugins\AppdataBackup\ABRetention;
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
$setCreated    = false; // only a set folder this run made may get its checksums, logs, -failed rename and permissions
$backupDone    = false; // set before Retention: an abort after it must not fail the set retention relies on

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
if (in_array('extra', $runArgs, true)) {
    ABHelper::$targetLogLevel = $abSettings->forSchedule('extra')->notification; // the errors below notify at this run's level; loading the settings set the Settings tab's
}
if (($abUnreadable = ABSettings::unreadableWarning()) !== '') {
    ABHelper::backupLog($abUnreadable, ABHelper::LOGLEVEL_ERR);
    ABHelper::$errorOccured = true; // a default may leave things out of the set: it is marked failed, and retention does not run
}

// Retention never deletes either schedule's destination, even one picked inside the other and named like a set
$abDestinations = array_filter(array_map(fn($path) => $path === '' ? false : realpath($path), [$abSettings->destination, $abSettings->extraDestination]));

if (in_array('extra', $runArgs, true)) {
    ABHelper::backupLog("Running the extra schedule: only its chosen containers, into its own destination.");
    if ($abSettings->extraSchedule !== 'yes') {
        ABHelper::backupLog(ABSettings::unread('/^extraSchedule$/') ? "The extra schedule did not run: Use an extra schedule? could not be read from config.json (see above)."
            : "The extra schedule is turned off (Settings, Use an extra schedule?).", ABHelper::LOGLEVEL_ERR);
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
}

if (empty($abSettings->destination)) {
    ABHelper::backupLog("Destination is not set!", ABHelper::LOGLEVEL_ERR);
    goto end;
}

ABSteps::plan($abSettings);
ABSteps::start('Preparing');

$setTime       = new DateTime();
$abDestination = ABHelper::freeSetFolder($abSettings->destination, $setTime);

ABHelper::handlePrePostScript($abSettings->preRunScript, 'pre-run', $abDestination);

if (!ABHelper::destinationUsable($abSettings->destination)) {
    ABHelper::backupLog("Destination is unavailable or not writeable! Did you create the destination folder?", ABHelper::LOGLEVEL_ERR);
    goto end;
}

$abDestination = ABHelper::freeSetFolder($abSettings->destination, $setTime); // again: the pre-run script may have just mounted the destination

if (!$abSettings->flashOnly()) {
    ABHelper::backupLog("Backing up from: " . implode(', ', $abSettings->allowedSources));
}

/**
 * At this point, we have something to work with.
 * Patch the destination for further usage
 */
ABHelper::backupLog("Backing up to: " . $abDestination);

if (basename($abDestination) !== 'ab_' . $setTime->format('Ymd_His')) {
    ABHelper::backupLog("A set named ab_" . $setTime->format('Ymd_His') . " already exists (was the clock set back, e.g. at the end of daylight saving time?), so this set is named " . basename($abDestination) . ".", ABHelper::LOGLEVEL_WARN);
}

if (!mkdir($abDestination)) {
    ABHelper::backupLog("Cannot create destination folder!", ABHelper::LOGLEVEL_ERR);
    goto end;
}
$setCreated = true;

// Retention tells the two types of set apart by this marker (ABSettings::flashOnlySet): without it, it would count this set wrongly
if ($abSettings->flashOnly() && @file_put_contents($abDestination . '/' . ABSettings::FLASH_ONLY_MARKER, '') === false) {
    ABHelper::backupLog("Cannot mark the new set as Flash only, so retention could not tell it apart: stopping", ABHelper::LOGLEVEL_ERR);
    ABHelper::$errorOccured = true;
    goto end;
}

if (ABHelper::abortRequested()) {
    goto abort;
}

if ($abSettings->flashOnly()) {
    ABHelper::backupLog("Backup type is Flash only: no container is stopped or backed up");
    goto continuationForAll;
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

ABHelper::backupLog("Container order: " . ($abSettings->containerOrderMode === 'custom' ? "the custom list from the settings" : "the Docker tab order, containers stop in reverse"), ABHelper::LOGLEVEL_DEBUG);
ABHelper::backupLog("Sorted Stop : " . implode(", ", array_column($sortedStopContainers, 'Name')), ABHelper::LOGLEVEL_DEBUG);
ABHelper::backupLog("Sorted Start: " . implode(", ", array_column($sortedStartContainers, 'Name')), ABHelper::LOGLEVEL_DEBUG);

ABHelper::backupLog($abSettings->schedule === 'extra' ? "Saving the XML files of the extra schedule's containers..." : "Saving container XML files...");
foreach (glob("/boot/config/plugins/dockerMan/templates-user/*") as $xmlFile) {
    // A template names its container in <Name>; the file name does not always match it
    if ($abSettings->schedule === 'extra' && !in_array((string)(@simplexml_load_file($xmlFile)->Name ?? ''), $abSettings->extraContainers, true)) {
        continue;
    }
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
if ($abSettings->backsUpFlash()) {
    ABSteps::start('Backing up the flash drive');
    if (!ABHelper::backupFlash($abDestination)) {
        ABHelper::$errorOccured = true;
    }
}

if (ABHelper::abortRequested()) {
    goto abort;
}

if ($abSettings->backsUpVMMeta()) {
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


if ($abSettings->backsUpExtraFiles()) {
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

if (!$setCreated || !is_dir($abDestination)) {
    ABHelper::$errorOccured = true; // an early exit made no backup set, so nothing below may treat the run as clean
} elseif (($setFiles = glob(ABHelper::globQuote($abDestination) . '/*')) === false) {
    ABHelper::backupLog("Cannot list $abDestination, so it cannot be flushed to disk!", ABHelper::LOGLEVEL_ERR);
    ABHelper::$errorOccured = true;
} elseif (!ABHelper::$errorOccured) {
    ABSteps::start('Writing checksums');
    ABIntegrity::writeChecksums($abDestination, $setFiles);
    // Retention deletes older sets, so this one goes to disk first
    ABHelper::backupLog("Flushing the backup to disk...");
    if (($said = ABHelper::flush(array_merge($setFiles, array_filter([$abDestination . '/' . ABIntegrity::FILE], 'is_file'), [$abDestination]))) !== null && !ABHelper::abortRequested()) { // an abort is handled below
        ABHelper::backupLog("Flushing the backup to disk failed! sync said: $said", ABHelper::LOGLEVEL_ERR);
        ABHelper::$errorOccured = true;
    }
}

// An abort during the checksums or the flush must not let retention delete older sets
if (ABHelper::abortRequested()) {
    goto abort;
}
$backupDone = !ABHelper::$errorOccured;

ABSteps::start('Retention');
if (ABHelper::$errorOccured) {
    ABHelper::backupLog("An error occurred during backup! RETENTION WILL NOT BE CHECKED! Please review the log. If you need further assistance, ask in the support forum.", ABHelper::LOGLEVEL_WARN);
} else {
    if (($off = ABRetention::off($abSettings)) !== '') {
        ABHelper::backupLog("Retention is off ($off): every backup set is kept", ABHelper::LOGLEVEL_WARN);
    } else { // Retention enabled
        // glob sorts by name, so oldest first. Only real set names: another ab_* folder here (e.g. the extra schedule's destination) is not a backup.
        $curBackupsState   = array_values(array_filter(array_reverse(glob(ABHelper::globQuote(rtrim($abSettings->destination, '/')) . '/ab_*')), fn($backupItem) => preg_match(ABStatus::SET_PATTERN, basename($backupItem)) && !in_array(realpath($backupItem), $abDestinations, true)));
        // Flash only sets (marked when created) and the others keep and delete only their own type, so switching type never deletes the other's
        $otherKind       = array_filter($curBackupsState, fn($backupItem) => $backupItem !== $abDestination && ABSettings::flashOnlySet($backupItem) !== $abSettings->flashOnly());
        $curBackupsState = array_values(array_diff($curBackupsState, $otherKind));
        if ($otherKind) {
            ABHelper::backupLog("Retention leaves " . count($otherKind) . " set(s) of the other Backup type here alone: it only counts and deletes this type's sets");
        }

        // Only finished, successful sets count towards Keep at least and stand for a GFS period. This run's set gets its backup.log before any delete.
        $goodBackups = array_values(array_filter($curBackupsState, fn($backupItem) => $backupItem === $abDestination || (!str_ends_with($backupItem, '-failed') && file_exists($backupItem . '/backup.log'))));

        $toKeep = ABRetention::keep($abSettings, $curBackupsState, $goodBackups, new DateTime());
        if (in_array($abDestination, $curBackupsState, true)) {
            $toKeep[$abDestination] ??= "this run's own set"; // a set named later in its period (a clock change) would outrank it
        }
        ABHelper::backupLog("Retention: " . ABRetention::describe($abSettings) . "; keeping " . count($toKeep) . " of " . count($curBackupsState) . " set(s)");
        foreach ($toKeep as $backupItem => $why) {
            ABHelper::backupLog("Keeping $backupItem: $why", ABHelper::LOGLEVEL_DEBUG);
        }

        $toDelete   = array_diff($curBackupsState, array_keys($toKeep));
        $notDeleted = [];
        if ($toDelete && !ABStatus::markFinished($abDestination)) { // unmarked, the next run would not count this set and could delete it too
            ABHelper::backupLog("Could not write backup.log into $abDestination, so retention deletes nothing this run", ABHelper::LOGLEVEL_WARN);
            $toDelete = [];
        } elseif ($toDelete && ($said = ABHelper::flush(["$abDestination/backup.log", $abDestination, dirname($abDestination)])) !== null && !ABHelper::abortRequested()) { // a power cut after the deletes must not lose the mark; an abort stops the loop below
            ABHelper::backupLog("Could not flush backup.log in $abDestination to disk, so retention deletes nothing this run. sync said: $said", ABHelper::LOGLEVEL_WARN);
            $toDelete = [];
        }
        foreach ($toDelete as $deleteBackupPath) {
            if (ABHelper::abortRequested()) {
                ABHelper::backupLog("Abort: retention stops, the sets not deleted yet stay");
                break;
            }
            ABHelper::backupLog("Delete old backup: " . $deleteBackupPath);
            $rmOut = [];
            exec("rm -rf " . escapeshellarg($deleteBackupPath) . " 2>&1 " . ABSettings::$externalCmdPidCapture, $rmOut, $rmCode);
            if ($rmCode !== 0) {
                $notDeleted[] = "$deleteBackupPath (rm exited $rmCode" . (ABHelper::abortRequested() ? ', Abort was pressed' : '') . "): " . implode(' ', array_slice($rmOut, 0, 3));
            }
        }
        if ($notDeleted) { // one warning (= one notification): one cause, such as a read-only disk, usually fails them all
            ABHelper::backupLog("Could not delete all of $notDeleted[0]" . (count($notDeleted) > 1 ? ' (and ' . (count($notDeleted) - 1) . ' more set(s), named in the debug log)' : ''), ABHelper::LOGLEVEL_WARN);
            foreach (array_slice($notDeleted, 1) as $line) {
                ABHelper::backupLog("Could not delete all of $line", ABHelper::LOGLEVEL_DEBUG);
            }
        }

    }
}

abort:
ABHelper::setCurrentContainerName(null);
ABSteps::start('Finishing');
ABSnapshot::destroyAll(); // an aborted run can still hold snapshots
if (ABHelper::abortRequested()) {
    if ($backupDone) {
        ABHelper::backupLog("Abort came after the backup had finished, so this run's set stays good", ABHelper::LOGLEVEL_WARN);
    } else {
        ABHelper::$errorOccured = true;
        ABHelper::startAfterAbort();
    }
}

ABHelper::reportRunNotes();
ABHelper::backupLog("DONE! Thanks for using this plugin and have a safe day ;)");
ABHelper::backupLog("❤️");

sleep(1); # In some cases a backup could create two notifications in a row, Unraid discards the latter then, so sleep 1 second

if (!ABHelper::$errorOccured && $abSettings->successLogWanted == 'yes') {
    $backupEnded    = new DateTime();
    $diff           = $backupStarted->diff($backupEnded);
    $backupDuration = $diff->h . "h, " . $diff->i . "m";
    ABHelper::notify("Appdata Backup", "Backup done [$backupDuration]!", "The backup was successful and took $backupDuration!");
}

if ($setCreated && is_dir($abDestination)) {
    if (!ABStatus::markFinished($abDestination)) {
        ABHelper::backupLog(is_file("$abDestination/backup.log") ? "Could not update backup.log in $abDestination, so it lacks this run's last lines"
            : "Could not copy backup.log into $abDestination, so retention will count this set as unfinished", ABHelper::LOGLEVEL_WARN);
    }
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
