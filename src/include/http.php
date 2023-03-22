<?php

require_once __DIR__ . '/ABSettings.php';
require_once __DIR__ . '/ABHelper.php';

use unraid\plugins\AppdataBackup\ABHelper;
use unraid\plugins\AppdataBackup\ABSettings;

if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    switch ($_GET['action']) {
        case 'getBackupState':

            $log     = "";
            $logFile = ABSettings::$tempFolder . '/' . ABSettings::$logfile;

            if (file_exists($logFile)) {
                $log = nl2br(file_get_contents($logFile));
            }

            $data = [
                'running' => ABHelper::backupRunning(),
                'log'     => $log
            ];

            echo json_encode($data);

            break;
        case 'manualBackup':
            exec('php ' . dirname(__DIR__) . '/scripts/backup.php > /dev/null &');
            break;
        case 'abort':
            touch(ABSettings::$tempFolder . '/' . ABSettings::$stateFileAbort);
            if (ABHelper::backupRunning()) {
                ABHelper::backupLog("User want to abort - please wait until current action is done.", ABHelper::LOGLEVEL_WARN);
            }
            break;
    }
}