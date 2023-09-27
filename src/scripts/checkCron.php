<?php

use unraid\plugins\AppdataBackup\ABSettings;

require_once(dirname(__DIR__) . '/include/ABSettings.php');

echo "Checking cron." . PHP_EOL;

$abSettings = new ABSettings();
list($code, $out) = $abSettings->checkCron();

if ($code == 0) {
    echo "Checking cron succeeded!" . PHP_EOL;
} else {
    echo "Error occurred: " . implode('; ', $out);
}



exit(0);