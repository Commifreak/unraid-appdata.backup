<?php

require_once __DIR__ . '/ABSettings.php';
require_once __DIR__ . '/ABHelper.php';

use unraid\plugins\AppdataBackup\ABHelper;
use unraid\plugins\AppdataBackup\ABSettings;

if (isset($_GET['action'])) {

    if ($_GET['action'] != 'dlLog') {
        header('Content-Type: application/json; charset=utf-8');
    }

    switch ($_GET['action']) {
        case 'getBackupState':

            $log     = "";
            $logFile = ABSettings::$tempFolder . '/' . ABSettings::$logfile;

            if (file_exists($logFile)) {
                $log = nl2br(file_get_contents($logFile));
            }

            $data = [
                'running' => ABHelper::scriptRunning(),
                'log'     => $log
            ];

            echo json_encode($data);

            break;
        case 'manualBackup':
            exec('php ' . dirname(__DIR__) . '/scripts/backup.php > /dev/null &');
            break;
        case 'abort':
            touch(ABSettings::$tempFolder . '/' . ABSettings::$stateFileAbort);
            if (ABHelper::scriptRunning()) {
                ABHelper::backupLog("User want to abort! Please wait!", ABHelper::LOGLEVEL_WARN);
                $extCmdPid = ABHelper::scriptRunning(true);
                if ($extCmdPid) {
                    ABHelper::backupLog("External cmd running, stopping PID " . $extCmdPid, ABHelper::LOGLEVEL_DEBUG);
                    exec("kill " . $extCmdPid);
                }
            }
            break;
        case 'checkRestoreSource':

            $files = glob(rtrim($_GET['src'], '/') . "/ab_*");
            if (empty($files)) {
                echo json_encode(['result' => false]);
                exit;
            }
            $result = [];
            $files  = array_reverse($files);
            foreach ($files as $file) {
                $date = date_create_from_format("??_Ymd_His", array_reverse(explode('/', $file))[0]);
                if (!$date || !is_dir($file)) {
                    continue;
                }
                $result[] = [
                    'path' => $file,
                    'name' => $date->format('d.m.Y H:i:s')
                ];

            }
            echo json_encode(['result' => $result]);
            break;

        case 'checkRestoreItem':
            $item = $_GET['item'];

            if (empty($item) || !file_exists($item)) {
                echo json_encode(['result' => false]);
                exit;
            }

            $config = [
                'configFile'    => false,
                'containers'    => false,
                'extraFiles'    => false,
                'templateFiles' => false,
                'vmMeta'        => false
            ];

            $backupFiles = scandir($item);


            /**
             * @Todo: write a meta file to the backup which holds the following infos. Makes changes in the structure more compatible
             */

            foreach ($backupFiles as $backupFile) {
                if ($backupFile == ABSettings::$settingsFile) {
                    $config['configFile'] = $backupFile;
                } elseif (str_starts_with($backupFile, 'extra_files.tar')) {
                    $config['extraFiles'] = $backupFile;
                } elseif ($backupFile == 'vm_meta.tgz') {
                    $config['vmMeta'] = $backupFile;
                } elseif (str_ends_with($backupFile, '.xml')) {
                    if (!$config['templateFiles']) {
                        $config['templateFiles'] = [];
                    }
                    $config['templateFiles'][] = $backupFile;
                } elseif (str_contains($backupFile, 'tar')) {
                    if (!$config['containers']) {
                        $config['containers'] = [];
                    }
                    $config['containers'][] = $backupFile;
                }
            }

            echo json_encode(['result' => $config]);
            break;
        case 'startRestore':
            exec('php ' . dirname(__DIR__) . '/scripts/restore.php ' . escapeshellarg(json_encode($_GET)) . ' > /dev/null &');
            break;
        case 'shareLog':
            $log    = ABSettings::$tempFolder . '/' . ABSettings::$debugLogFile;
            $config = ABSettings::getConfigPath();
            if (!file_exists($log)) {
                echo json_encode(['success' => false, 'msg' => 'Logfile does not exist!']);
                exit;
            }

            $ch = curl_init("https://kluthr.de/unraid/index.php");
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, ['log' => new CURLFile($log), 'config' => new CURLFile($config)]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

            $result = curl_exec($ch);
            $info   = curl_getinfo($ch);

            if ($result && $info['http_code'] == 200) {
                echo json_encode(['success' => true, 'msg' => $result]);
            } else {
                echo json_encode(['success' => false, 'msg' => $result ? $result : curl_error($ch)]);
            }


            break;


    }
}