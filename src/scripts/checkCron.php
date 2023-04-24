<?php

use unraid\plugins\AppdataBackup\ABSettings;

require_once(dirname(__DIR__) . '/include/ABSettings.php');

echo "Manually checking cron." . PHP_EOL;

$abSettings = new ABSettings();
$abSettings->checkCron();

echo "Checking cron succeeded!" . PHP_EOL;

exit(0);