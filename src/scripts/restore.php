<?php

use unraid\plugins\AppdataBackup\ABHelper;
use unraid\plugins\AppdataBackup\ABSettings;

require_once __DIR__ . '/../include/ABHelper.php';

//set_error_handler("unraid\plugins\AppdataBackup\ABHelper::errorHandler");

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

file_put_contents(ABSettings::$tempFolder . '/' . ABSettings::$stateFileScriptRunning, getmypid());

ABHelper::backupLog("👋 WELCOME TO APPDATA.BACKUP (in restore mode)!! :D");

$unraidVersion           = parse_ini_file('/etc/unraid-version');
$emhttpPluginVersionPath = '/usr/local/emhttp/plugins/' . ABSettings::$appName . '/version';
$pluginVersion           = file_exists($emhttpPluginVersionPath) ? file_get_contents($emhttpPluginVersionPath) : null;
ABHelper::backupLog("plugin-version: " . $pluginVersion, ABHelper::LOGLEVEL_DEBUG);
ABHelper::backupLog("unraid-version: " . print_r($unraidVersion, true), ABHelper::LOGLEVEL_DEBUG);

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

$tarDestination = empty(trim($config['customRestoreDestination'])) ? '/' : $config['customRestoreDestination'];
if (!file_exists($tarDestination)) {
    mkdir($tarDestination, 0777, true);
}

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
        foreach ($config['restoreItem']['containers'] as $container => $on) {
            ABHelper::backupLog("Restoring $container");

            $tarOptions = [
                '-C ' . escapeshellarg($tarDestination),
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

            $output = $resultcode = null;
            exec($finalTarCommand . " 2>&1 " . ABSettings::$externalCmdPidCapture, $output, $resultcode);
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
}


if (!isset($config['restoreItem']['extraFiles'])) {
    ABHelper::backupLog("Not restoring extra files: not wanted");
} else {

    ABHelper::backupLog("Restoring extra files...");

    $extraFile = file_exists($restoreSource . '/extra_files.tar.zst') ? 'extra_files.tar.zst' : 'extra_files.tar.gz';

    $tarOptions = [
        '-C ' . escapeshellarg($tarDestination),
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

    $output = $resultcode = null;
    exec($finalTarCommand . " 2>&1 " . ABSettings::$externalCmdPidCapture, $output, $resultcode);
    ABHelper::backupLog("Tar out: " . implode('; ', $output), ABHelper::LOGLEVEL_DEBUG);
    if ($resultcode > 0) {
        ABHelper::backupLog("restore failed! More output available inside debuglog, maybe.", ABHelper::LOGLEVEL_ERR);
    } else {
        ABHelper::backupLog("restore succeeded!");
    }
}

if (ABHelper::abortRequested()) {
    goto abort;
}

if (!isset($config['restoreItem']['vmMeta'])) {
    ABHelper::backupLog("Not restoring VM meta: not wanted");
} else {

    ABHelper::backupLog("Restoring VM meta...");

    if (!file_exists(ABSettings::$qemuFolder)) {
        ABHelper::backupLog("VM manager is NOT enabled! Cannot restore VM meta", ABHelper::LOGLEVEL_ERR);
    } else {
        $output = $resultcode = null;
        exec('tar -C ' . escapeshellarg($tarDestination) . ' -xzf ' . escapeshellarg($restoreSource . '/vm_meta.tgz') . " " . ABSettings::$externalCmdPidCapture, $output, $resultcode);
        ABHelper::backupLog("tar return: $resultcode, output: " . print_r($output, true), ABHelper::LOGLEVEL_DEBUG);
        if ($resultcode != 0) {
            ABHelper::backupLog("restore failed, please see debug log.", ABHelper::LOGLEVEL_ERR);
        } else {
            ABHelper::backupLog("restoring vm meta succeeded!");
        }
    }

}

abort:

ABHelper::backupLog("Restore complete!");

unlink(ABSettings::$tempFolder . '/' . ABSettings::$stateFileScriptRunning);