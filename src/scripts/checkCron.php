<?php

use unraid\plugins\AppdataBackup\ABSettings;

require_once(dirname(__DIR__) . '/include/ABSettings.php');

echo "Checking cron." . PHP_EOL;

if (($argv[1] ?? null) == '--remove') {
    @unlink('/etc/cron.d/appdata_backup');
    exit;
}

$abSettings = new ABSettings();
$abSettings->checkCron();

echo "Checking cron succeeded!" . PHP_EOL;

exit(0);