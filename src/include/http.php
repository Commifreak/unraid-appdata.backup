<?php

require_once __DIR__ . '/ABSettings.php';

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
                'running' => file_exists(ABSettings::$tempFolder . '/' . ABSettings::$stateFileBackupInProgress),
                'log'     => $log
            ];

            echo json_encode($data);

            break;
    }
}