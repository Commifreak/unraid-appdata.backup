<?php

require_once __DIR__ . '/ABSettings.php';
require_once __DIR__ . '/ABHelper.php';

use unraid\plugins\AppdataBackup\ABHelper;
use unraid\plugins\AppdataBackup\ABIntegrity;
use unraid\plugins\AppdataBackup\ABSettings;

$writeActions = ['manualBackup', 'extraBackup', 'abort', 'startRestore', 'verifySet', 'copyConfigFromProd'];
$isPost       = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$action       = $isPost ? ($_POST['action'] ?? null) : ($_GET['action'] ?? null);

if (isset($action)) {

    // State changes are POST-only so Unraid's local_prepend.php enforces csrf_token on them.
    if (in_array($action, $writeActions) !== $isPost) {
        http_response_code(405);
        exit;
    }

    if (!in_array($action, ['dlLog', 'copyConfigFromProd'])) {
        header('Content-Type: application/json; charset=utf-8');
    }

    switch ($action) {
        case 'getBackupState':

            $log     = "";
            $logFile = $_GET['logType'] == 'normal' ? ABSettings::$tempFolder . '/' . ABSettings::$logfile : ABSettings::$tempFolder . '/' . ABSettings::$debugLogFile;

            if (file_exists($logFile)) {
                $log = nl2br(htmlspecialchars(file_get_contents($logFile))); // the Status tab inserts this as HTML
            }

            $running  = ABHelper::scriptRunning();
            $stepFile = ABSettings::$tempFolder . '/' . ABSettings::$stateFileStep;
            // A step file older than the running file is left over from an earlier run (or a verify or restore is running)
            $step = $running && @filemtime($stepFile) >= @filemtime(ABSettings::$tempFolder . '/' . ABSettings::$stateFileScriptRunning) ? (string)@file_get_contents($stepFile) : '';

            $data = [
                'running' => $running,
                'job'     => $running ? ABHelper::runningJob($running) : '',
                'log'     => $log,
                'step'    => $step
            ];

            echo json_encode($data);

            break;
        case 'getStatus':
            require_once __DIR__ . '/ABStatus.php';
            $abSettings = new ABSettings();
            ob_start();
            include dirname(__DIR__) . '/pages/content/status.php';
            echo json_encode(['html' => ob_get_clean()]);
            break;
        case 'manualBackup':
            exec('php ' . dirname(__DIR__) . '/scripts/backup.php > /dev/null &');
            break;
        case 'extraBackup':
            exec('php ' . dirname(__DIR__) . '/scripts/backup.php extra > /dev/null &');
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

            $files = glob(ABHelper::globQuote(rtrim($_GET['src'], '/')) . "/ab_*");
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
                    'path'      => $file,
                    'name'      => $date->format('d.m.Y H:i:s'),
                    'checksums' => is_file($file . '/' . ABIntegrity::FILE)
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
            exec('php ' . dirname(__DIR__) . '/scripts/restore.php ' . escapeshellarg(json_encode($_POST)) . ' > /dev/null &');
            break;
        case 'verifySet':
            exec('php ' . escapeshellarg(dirname(__DIR__) . '/scripts/verify.php') . ' ' . escapeshellarg((string)($_POST['set'] ?? '')) . ' > /dev/null &');
            break;

        case 'copyConfigFromProd':
            if (file_exists("/boot/config/plugins/appdata.backup/config.json")) {
                copy("/boot/config/plugins/appdata.backup/config.json", "/boot/config/plugins/appdata.backup.beta/config.json");
                echo "Config found and copied!";
            } else {
                echo "No productive config found :/";
            }
            break;
    }
}