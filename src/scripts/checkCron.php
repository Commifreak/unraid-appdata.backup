<?php

use unraid\plugins\AppdataBackup\ABSettings;

require_once(dirname(__DIR__) . '/include/ABSettings.php');

if ($argv[1] == '--remove') {
    @unlink('/etc/cron.d/appdata_backup');
    exit;
}

$abSettings = new ABSettings();
$abSettings->checkCron();