<?php

use unraid\plugins\AppdataBackup\ABHelper;
use unraid\plugins\AppdataBackup\ABIntegrity;
use unraid\plugins\AppdataBackup\ABSettings;

// CLI only: nginx runs any .php under the plugin folder for a logged-in GET.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../include/ABHelper.php';

if (!ABHelper::claimRun()) {
    ABHelper::notify("Appdata Backup", "Still running", "There is something running already.");
    exit;
}

if (file_exists(ABSettings::$tempFolder . '/' . ABSettings::$stateFileAbort)) {
    unlink(ABSettings::$tempFolder . '/' . ABSettings::$stateFileAbort);
}
exec("rm -f " . escapeshellarg(ABSettings::$tempFolder) . "/*.log");

ABHelper::backupLog("👋 WELCOME TO APPDATA.BACKUP (checking a backup)!! :D");
$ok = ABIntegrity::verifySet($argv[1] ?? '');
ABHelper::backupLog("DONE!");

unlink(ABSettings::$tempFolder . '/' . ABSettings::$stateFileScriptRunning);
exit($ok ? 0 : 1);
