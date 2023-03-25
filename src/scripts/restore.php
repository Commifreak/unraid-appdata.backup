<?php

use unraid\plugins\AppdataBackup\ABHelper;
use unraid\plugins\AppdataBackup\ABSettings;

require_once __DIR__ . '/../include/ABHelper.php';

if (ABHelper::scriptRunning()) {
    ABHelper::notify("Still running", "There is something running already.");
    exit;
}

if (file_exists(ABSettings::$tempFolder . '/' . ABSettings::$stateFileAbort)) {
    unlink(ABSettings::$tempFolder . '/' . ABSettings::$stateFileAbort);
}

if (file_exists(ABSettings::$tempFolder)) {
    exec("rm " . ABSettings::$tempFolder . '/*.log');
} // Creation of tempFolder is handled by backupLog

ABHelper::backupLog("👋 WELCOME TO APPDATA.BACKUP (in restore mode)!! :D");

file_put_contents(ABSettings::$tempFolder . '/' . ABSettings::$stateFileScriptRunning, getmypid());

/**
 * Some basic checks
 */
if (!ABHelper::isArrayOnline()) {
    ABHelper::backupLog("It doesn't appear that the array is running!", ABHelper::LOGLEVEL_ERR);
    exit;
}

ABHelper::backupLog(print_r($argv, true), ABHelper::LOGLEVEL_DEBUG);

$config = json_decode($argv[1], true);
ABHelper::backupLog(print_r($config, true), ABHelper::LOGLEVEL_DEBUG);

$restoreSource = $config['restoreBackupList'];


if (!isset($config['restoreItem']['config'])) {
    ABHelper::backupLog("Not restoring config: not wanted");
} else {
    if (!file_exists(ABSettings::$pluginDir)) {
        ABHelper::backupLog("Plugin dir (" . ABSettings::$pluginDir . ") does not exist, creating it", ABHelper::LOGLEVEL_DEBUG);
        mkdir(ABSettings::$pluginDir);
    }
    if (copy($restoreSource . '/config.json', ABSettings::getConfigPath())) {
        ABHelper::backupLog("Settings restored!");
    } else {
        ABHelper::backupLog("Something went wrong while restoring settings!", ABHelper::LOGLEVEL_ERR);
    }
}

if (ABHelper::abortRequested()) {
    goto abort;
}


if (!isset($config['restoreItem']['templates'])) {
    ABHelper::backupLog("Not restoring templates: not wanted");
} else {
    $xmlDir = "/boot/config/plugins/dockerMan/templates-user";
    if (!file_exists($xmlDir)) {
        ABHelper::backupLog("Template dir (" . $xmlDir . ") does not exist!", ABHelper::LOGLEVEL_ERR);
    } else {
        foreach ($config['restoreItem']['templates'] as $template => $on) {
            if (copy($restoreSource . '/' . $template, $xmlDir . '/' . $template)) {
                ABHelper::backupLog("Template '$template' restored!");
            } else {
                ABHelper::backupLog("Something went wrong while restoring template '$template'!", ABHelper::LOGLEVEL_ERR);
            }
        }
    }
}

if (ABHelper::abortRequested()) {
    goto abort;
}

if (!isset($config['restoreItem']['containers'])) {
    ABHelper::backupLog("Not restoring containers: not wanted");
} else {
    $dockerCfg = parse_ini_file(ABSettings::$dockerIniFile);
    if ($dockerCfg && isset($dockerCfg['DOCKER_APP_CONFIG_PATH'])) {
        $appdataPath = $dockerCfg['DOCKER_APP_CONFIG_PATH'];
        foreach ($config['restoreItem']['containers'] as $container => $on) {
            if (file_exists($appdataPath . '/' . explode('.', $container)[0])) {
                ABHelper::backupLog("The destination already exists! Skipping.", ABHelper::LOGLEVEL_WARN);
                continue;
            }
            ABHelper::backupLog("Restoring $container");

            $tarOptions = [
                '-C ' . escapeshellarg($appdataPath),
                '-x',
                '-f ' . escapeshellarg($restoreSource . '/' . $container)
            ];

            if (str_ends_with($container, 'zst')) {
                $tarOptions[] = '-I zstd';
            } else {
                $tarOptions[] = '-z';
            }

            $finalTarCommand = 'tar ' . implode(' ', $tarOptions);
            ABHelper::backupLog("Final tar command: " . $finalTarCommand, ABHelper::LOGLEVEL_DEBUG);

            exec($finalTarCommand . " 2>&1", $output, $resultcode);
            ABHelper::backupLog("Tar out: " . implode('; ', $output), ABHelper::LOGLEVEL_DEBUG);
            if ($resultcode > 0) {
                ABHelper::backupLog("restore failed! More output available inside debuglog, maybe.", ABHelper::LOGLEVEL_ERR);
            } else {
                ABHelper::backupLog("restore succeeded!");
            }

            if (ABHelper::abortRequested()) {
                goto abort;
            }
        }
    } else {
        ABHelper::backupLog("docker.cfg not found!", ABHelper::LOGLEVEL_ERR);
    }
}


if (!isset($config['restoreItem']['extraFiles'])) {
    ABHelper::backupLog("Not restoring extra files: not wanted");
} else {

    ABHelper::backupLog("Restoring extra files...");

    $extraFile = file_exists($restoreSource . '/extra_files.tar.zst') ? 'extra_files.tar.zst' : 'extra_files.tar.gz';

    $tarOptions = [
        '-C /',
        '-x',
        '-f ' . escapeshellarg($restoreSource . '/' . $extraFile)
    ];

    if (str_ends_with($extraFile, 'zst')) {
        $tarOptions[] = '-I zstd';
    } else {
        $tarOptions[] = '-z';
    }

    $finalTarCommand = 'tar ' . implode(' ', $tarOptions);
    ABHelper::backupLog("Final tar command: " . $finalTarCommand, ABHelper::LOGLEVEL_DEBUG);

    exec($finalTarCommand . " 2>&1", $output, $resultcode);
    ABHelper::backupLog("Tar out: " . implode('; ', $output), ABHelper::LOGLEVEL_DEBUG);
    if ($resultcode > 0) {
        ABHelper::backupLog("restore failed! More output available inside debuglog, maybe.", ABHelper::LOGLEVEL_ERR);
    } else {
        ABHelper::backupLog("restore succeeded!");
    }
}

abort:

ABHelper::backupLog("Restore complete!");

unlink(ABSettings::$tempFolder . '/' . ABSettings::$stateFileScriptRunning);