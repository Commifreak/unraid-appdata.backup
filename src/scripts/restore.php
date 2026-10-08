<?php

use unraid\plugins\AppdataBackup\ABHelper;
use unraid\plugins\AppdataBackup\ABSettings;

// CLI only: nginx runs any .php under the plugin folder for a logged-in GET.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../include/ABHelper.php';

//set_error_handler("unraid\plugins\AppdataBackup\ABHelper::errorHandler");

if (!ABHelper::claimRun()) {
    exit;
}

ABHelper::backupLog("👋 WELCOME TO APPDATA.BACKUP (in restore mode)!! :D");

$unraidVersion           = parse_ini_file('/etc/unraid-version');
$emhttpPluginVersionPath = '/usr/local/emhttp/plugins/' . ABSettings::$appName . '/version';
$pluginVersion           = file_exists($emhttpPluginVersionPath) ? file_get_contents($emhttpPluginVersionPath) : null;
ABHelper::backupLog("plugin-version: " . $pluginVersion, ABHelper::LOGLEVEL_DEBUG);
ABHelper::backupLog(ABHelper::dump('unraid-version', $unraidVersion), ABHelper::LOGLEVEL_DEBUG);

/**
 * Some basic checks
 */
if (!ABHelper::isArrayOnline()) {
    ABHelper::backupLog("It doesn't appear that the array is running!", ABHelper::LOGLEVEL_ERR);
    exit;
}

ABHelper::backupLog(ABHelper::dump('Arguments', $argv), ABHelper::LOGLEVEL_DEBUG);

$config = json_decode($argv[1], true);
ABHelper::backupLog(ABHelper::dump('Restore settings', $config), ABHelper::LOGLEVEL_DEBUG);

$tarDestination = empty(trim($config['customRestoreDestination'])) ? '/' : $config['customRestoreDestination'];
if (!file_exists($tarDestination)) {
    mkdir($tarDestination, 0777, true);
}

$restoreSource = $config['restoreBackupList'];
$restoreFailed = false;


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
        $restoreFailed = true;
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
        $restoreFailed = true;
    } else {
        foreach ($config['restoreItem']['templates'] as $template => $on) {
            if (copy($restoreSource . '/' . $template, $xmlDir . '/' . $template)) {
                ABHelper::backupLog("Template '$template' restored!");
            } else {
                ABHelper::backupLog("Something went wrong while restoring template '$template'!", ABHelper::LOGLEVEL_ERR);
                $restoreFailed = true;
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
    // Only a restore to the original folders touches live data, so only then are running containers stopped
    $installed = [];
    if ($tarDestination === '/') {
        require_once '/usr/local/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php';
        $dockerClient = new DockerClient();
        $installed    = array_column($dockerClient->getDockerContainers() ?: [], null, 'Name');
    }
    foreach ($config['restoreItem']['containers'] as $container => $on) {
        $name       = preg_replace(ABHelper::ARCHIVE_PATTERN, '', $container);
        $wasRunning = !empty($installed[$name]['Running']);
        ABHelper::backupLog("Restoring $container");

        if ($wasRunning) {
            ABHelper::backupLog("Stopping $name for its restore... ", ABHelper::LOGLEVEL_INFO, false);
            $stopped = ABHelper::stopRunning($name);
            if ($stopped === null) {
                ABHelper::backupLog("The state of '$name' cannot be read after the stop, so it is not restored! It will be started again.", ABHelper::LOGLEVEL_ERR);
                $restoreFailed = true;
                ABHelper::startContainer(['Name' => $name]);
                continue;
            }
            if (!$stopped) {
                ABHelper::backupLog("'$name' did not stop, so it is not restored!", ABHelper::LOGLEVEL_ERR);
                $restoreFailed = true;
                continue;
            }
        }

        $tarOptions = [
            '-C ' . escapeshellarg($tarDestination),
            '-x',
            '-f ' . escapeshellarg($restoreSource . '/' . $container)
        ];

        if (str_ends_with($container, 'zst')) {
            $tarOptions[] = '-I zstd';
        } elseif (str_ends_with($container, 'gz')) {
            $tarOptions[] = '-z';
        }

        $finalTarCommand = 'tar ' . implode(' ', $tarOptions);
        ABHelper::backupLog("Final tar command: " . $finalTarCommand, ABHelper::LOGLEVEL_DEBUG);

        $output = $resultcode = null;
        exec($finalTarCommand . " 2>&1 " . ABSettings::$externalCmdPidCapture, $output, $resultcode);
        ABHelper::backupLog("Tar out: " . implode('; ', $output), ABHelper::LOGLEVEL_DEBUG);
        if ($resultcode > 0) {
            ABHelper::backupLog("restore failed! Tar said: " . implode('; ', $output), ABHelper::LOGLEVEL_ERR);
            $restoreFailed = true;
        } else {
            ABHelper::backupLog("restore succeeded!");
        }

        // Started again even after a failed or aborted extract: it was running before the restore
        if ($wasRunning) {
            ABHelper::startContainer(['Name' => $name]);
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


    /**
     * Improve this (for containers as well)
     */
    if (file_exists($restoreSource . '/extra_files.tar.zst')) {
        $extraFile = 'extra_files.tar.zst';
    } elseif (file_exists($restoreSource . '/extra_files.tar.gz')) {
        $extraFile = 'extra_files.tar.gz';
    } else {
        $extraFile = 'extra_files.tar';
    }

    $tarOptions = [
        '-C ' . escapeshellarg($tarDestination),
        '-x',
        '-f ' . escapeshellarg($restoreSource . '/' . $extraFile)
    ];

    if (str_ends_with($extraFile, 'zst')) {
        $tarOptions[] = '-I zstd';
    } elseif (str_ends_with($extraFile, 'gz')) {
        $tarOptions[] = '-z';
    }

    $finalTarCommand = 'tar ' . implode(' ', $tarOptions);
    ABHelper::backupLog("Final tar command: " . $finalTarCommand, ABHelper::LOGLEVEL_DEBUG);

    $output = $resultcode = null;
    exec($finalTarCommand . " 2>&1 " . ABSettings::$externalCmdPidCapture, $output, $resultcode);
    ABHelper::backupLog("Tar out: " . implode('; ', $output), ABHelper::LOGLEVEL_DEBUG);
    if ($resultcode > 0) {
        ABHelper::backupLog("restore failed! Tar said: " . implode('; ', $output), ABHelper::LOGLEVEL_ERR);
        $restoreFailed = true;
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
        $restoreFailed = true;
    } else {
        $output = $resultcode = null;
        exec('tar -C ' . escapeshellarg($tarDestination) . ' -xzf ' . escapeshellarg($restoreSource . '/vm_meta.tgz') . " 2>&1 " . ABSettings::$externalCmdPidCapture, $output, $resultcode);
        ABHelper::backupLog(ABHelper::dump("tar return: $resultcode, output", $output), ABHelper::LOGLEVEL_DEBUG);
        if ($resultcode != 0) {
            ABHelper::backupLog("Restoring VM meta failed! Tar said: " . implode('; ', $output), ABHelper::LOGLEVEL_ERR);
            $restoreFailed = true;
        } else {
            ABHelper::backupLog("restoring vm meta succeeded!");
        }
    }

}

abort:

ABHelper::backupLog($restoreFailed ? "Restore finished with errors, see above." : "Restore complete!", $restoreFailed ? ABHelper::LOGLEVEL_ERR : ABHelper::LOGLEVEL_INFO);

unlink(ABSettings::$tempFolder . '/' . ABSettings::$stateFileScriptRunning);