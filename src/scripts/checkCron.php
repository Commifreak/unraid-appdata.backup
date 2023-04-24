<?php

use unraid\plugins\AppdataBackup\ABSettings;

require_once(dirname(__DIR__) . '/include/ABSettings.php');

echo "Checking cron." . PHP_EOL;

/**
 * Old cron style remnants - ged rid of it
 */
if (file_exists('/etc/cron.d/appdata_backup')) {
    @unlink('/etc/cron.d/appdata_backup');
}
if (file_exists('/etc/cron.d/appdata_backup_beta')) {
    @unlink('/etc/cron.d/appdata_backup_beta');
}

if (($argv[1] ?? null) == '--remove') {
    @unlink(ABSettings::$pluginDir . '/' . ABSettings::$cronFile);
    echo "cronfile deleted!" . PHP_EOL;
    exit;
}

$abSettings = new ABSettings();
$abSettings->checkCron();

echo "Checking cron succeeded!" . PHP_EOL;

exit(0);