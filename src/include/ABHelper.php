<?php

namespace unraid\plugins\AppdataBackup;

require_once __DIR__ . '/ABSettings.php';

class ABHelper {

    const LOGLEVEL_DEBUG = 'debug';
    const LOGLEVEL_INFO = 'info';
    const LOGLEVEL_WARN = 'warning';
    const LOGLEVEL_ERR = 'error';

    private static $skipStartContainers = [];

    public static $targetLogLevel = '';

    /**
     * Logs a message to the system log
     * @param $string
     * @return void
     */
    public static function logger($string) {
        shell_exec("logger -t 'Appdata Backup' " . escapeshellarg($string));
    }

    /**
     * Checks, if the Array is online
     * @return bool
     */
    public static function isArrayOnline() {
        $emhttpVars = parse_ini_file(ABSettings::$emhttpVars);
        if ($emhttpVars && $emhttpVars['fsState'] == 'Started') {
            return true;
        }
        return false;
    }

    public static function handlePrePostScript($script) {
        if (empty($script)) {
            self::backupLog("Not executing script: Not set!", self::LOGLEVEL_DEBUG);
            return true;
        }

        if (file_exists($script)) {
            if (!is_executable($script)) {
                self::backupLog($script . ' is not executable! Skipping!', self::LOGLEVEL_ERR);
                return false;
            }
            self::backupLog("Executing script $script...");
            exec(escapeshellarg($script), $output, $resultcode);
            self::backupLog($script . " CODE: " . $resultcode . " - " . print_r($output, true), self::LOGLEVEL_DEBUG);
            self::backupLog("Script executed!");

            if ($resultcode != 0) {
                self::backupLog("Script did not returned 0!", self::LOGLEVEL_WARN);
            }
        } else {
            self::backupLog($script . ' is not existing! Skipping!', self::LOGLEVEL_ERR);
            return false;
        }
        return true;
    }

    /**
     * Logs something to the backup logfile
     * @param $level string
     * @param $msg string
     * @param $newLine bool
     * @param $skipDate bool
     * @return void
     */
    public static function backupLog(string $msg, string $level = self::LOGLEVEL_INFO, bool $newLine = true, bool $skipDate = false) {

        if (!file_exists(ABSettings::$tempFolder)) {
            mkdir(ABSettings::$tempFolder);
        }

        if ($level != self::LOGLEVEL_DEBUG) {
            file_put_contents(ABSettings::$tempFolder . '/' . ABSettings::$logfile, ($skipDate ? '' : "[" . date("d.m.Y H:i:s") . "][$level]") . " $msg" . ($newLine ? "\n" : ''), FILE_APPEND);
        }
        file_put_contents(ABSettings::$tempFolder . '/' . ABSettings::$debugLogFile, ($skipDate ? '' : "[" . date("d.m.Y H:i:s") . "][$level]") . " $msg" . ($newLine ? "\n" : ''), FILE_APPEND);

        if ($level == self::LOGLEVEL_ERR && self::$targetLogLevel == self::LOGLEVEL_ERR) {
            self::notify("Error occured!", "Please check the backup log tab!", $msg, 'alert');
        }

        if ($level == self::LOGLEVEL_WARN && in_array(self::$targetLogLevel, [self::LOGLEVEL_WARN, self::LOGLEVEL_ERR])) {
            self::notify("Warning", "Please check the backup log tab!", $msg, 'warning');
        }
    }

    /**
     * Send a message to the system notification system
     * @param $subject
     * @param $description
     * @param $message
     * @param $type
     * @return void
     */
    public static function notify($subject, $description, $message = "", $type = "normal") {
        $command = '/usr/local/emhttp/plugins/dynamix/scripts/notify -e "Appdata Backup" -s "' . $subject . '" -d "' . $description . '" -m "' . $message . '" -i "' . $type . '" -l "/Settings/AB.Main"';
        shell_exec($command);
    }

    public static function stopContainer($container) {
        global $dockerClient;

        if ($container['Running'] && !$container['Paused']) {
            self::backupLog("Stopping " . $container['Name'] . "... ");
            $stopTimer      = time();
            $dockerStopCode = $dockerClient->stopContainer($container['Name']);
            if ($dockerStopCode != 1) {
                self::backupLog("Error while stopping container! Code: " . $dockerStopCode, self::LOGLEVEL_ERR);
            } else {
                self::backupLog("done! (took " . (time() - $stopTimer) . " seconds)");
            }

        } else {
            self::$skipStartContainers[] = $container['Name'];
            $state                       = "Not started!";
            if ($container['Paused']) {
                $state = "Paused!";
            }
            self::backupLog("No stopping needed for {$container['Name']}: $state");
        }
    }

    public static function startContainer($container) {
        global $dockerClient;

        if (in_array($container['Name'], self::$skipStartContainers)) {
            self::backupLog($container['Name'] . " is being ignored, because it was not started before.");
            return;
        }

        $dockerContainerStarted = false;
        $dockerStartTry         = 1;
        $delay                  = 0;

        $autostart = file("/var/lib/docker/unraid-autostart");
        if ($autostart) {
            foreach ($autostart as $autostartLine) {
                $line = explode(" ", trim($autostartLine));
                if ($line[0] == $container['Name'] && isset($line[1])) {
                    $delay = $line[1];
                    break;
                }
            }
        } else {
            self::backupLog("Docker autostart file is NOT present!", self::LOGLEVEL_DEBUG);
        }

        do {
            self::backupLog("Starting {$container['Name']}... (try #$dockerStartTry)");
            $dockerStartCode = $dockerClient->startContainer($container['Name']);
            if ($dockerStartCode != 1) {
                if ($dockerStartCode == "Container already started") {
                    self::backupLog("Hmm - container is already started!");
                    $nowRunning = $dockerClient->getDockerContainers();
                    foreach ($nowRunning as $nowRunningContainer) {
                        if ($nowRunningContainer["Name"] == $container['Name']) {
                            self::backupLog("AFTER backing up container status: " . print_r($nowRunningContainer, true), self::LOGLEVEL_DEBUG);
                        }
                    }
                    $dockerContainerStarted = true;
                    continue;
                }

                self::backupLog("Container did not started! - Code: " . $dockerStartCode, self::LOGLEVEL_WARN);
                if ($dockerStartTry < 3) {
                    $dockerStartTry++;
                    sleep(5);
                } else {
                    self::backupLog("Container did not started after multiple tries, skipping.", self::LOGLEVEL_ERR);
                    break; // Exit do-while
                }
            } else {
                $dockerContainerStarted = true;
            }
        } while (!$dockerContainerStarted);
        if ($delay) {
            self::backupLog("The container has a delay set, waiting $delay seconds before carrying on");
            sleep($delay);
        } else {
            // Sleep 2 seconds in general
            sleep(2);
        }
    }

    /**
     * Sort docker containers, provided by dynamix DockerClient and provided order array
     * @param $containers array DockerClient container array
     * @param $order array order array
     * @param $reverse bool return reverse order (unknown containers are always placed to the end of the returning array
     * @return array with name as key and DockerClient info-array as value
     */
    public static function sortContainers($containers, $order, $reverse = false, $removeSkipped = true) {
        global $abSettings;

        $_containers      = array_column($containers, null, 'Name');
        $sortedContainers = [];
        foreach ($order as $name) {
            $containerSettings = $abSettings->getContainerSpecificSettings($name, $removeSkipped);
            if ($containerSettings['skip'] == 'yes' && $removeSkipped) {
                self::backupLog("Not adding $name to sorted containers: should be ignored", self::LOGLEVEL_DEBUG);
                unset($_containers[$name]);
                continue;
            }
            $sortedContainers[] = $_containers[$name];
            unset($_containers[$name]);
        }
        if ($reverse) {
            $sortedContainers = array_reverse($sortedContainers);
        }
        return array_merge($sortedContainers, $_containers);
    }


    public static function backupContainer($container, $destination) {
        global $abSettings, $dockerClient;

        self::backupLog("Backup {$container['Name']} - Container Volumeinfo: " . print_r($container['Volumes'], true), self::LOGLEVEL_DEBUG);

        $stripAppdataPath = '';

        // Get default docker storage path
        $dockerCfgFile = '/boot/config/docker.cfg';
        if (file_exists($dockerCfgFile)) {
            self::backupLog("Parsing $dockerCfgFile", self::LOGLEVEL_DEBUG);
            $dockerCfg = parse_ini_file($dockerCfgFile);
            if ($dockerCfg) {
                if (isset($dockerCfg['DOCKER_APP_CONFIG_PATH'])) {
                    $stripAppdataPath = $dockerCfg['DOCKER_APP_CONFIG_PATH'];
                    self::backupLog("Got default appdataPath: $stripAppdataPath", self::LOGLEVEL_DEBUG);
                }
            } else {
                self::backupLog("Could not parse $dockerCfgFile", self::LOGLEVEL_DEBUG);
            }
        }


        $volumes = [];
        foreach ($container['Volumes'] ?? [] as $volume) {
            $hostPath = explode(":", $volume)[0];
            if (!empty($stripAppdataPath) && strpos($volume, $stripAppdataPath) === 0) {
                $hostPath = ltrim(str_replace($stripAppdataPath, '', $hostPath), '/');
            }
            $volumes[] = $hostPath;
        }

        if (empty($volumes)) {
            self::backupLog($container['Name'] . " does not have any volume to back up! Skipping");
            return true;
        }

        self::backupLog("Final volumes: " . implode(", ", $volumes), self::LOGLEVEL_DEBUG);

        $destination = $destination . "/" . $container['Name'] . '.tar';

        $containerSettings = $abSettings->getContainerSpecificSettings($container['Name']);

        $tarVerifyOptions = ['--diff'];
        $tarOptions       = ['-c'];

        if (!empty($stripAppdataPath)) {
            $tarOptions[] = $tarVerifyOptions[] = '-C ' . escapeshellarg($stripAppdataPath);
        }

        switch ($abSettings->compression) {
            case 'yes':
                $tarOptions[] = '-z'; // GZip
                $destination  .= '.gz';
                break;
            case 'yesMulticore':
                $tarOptions[] = '-I zstdmt'; // zst multicore
                $destination  .= '.zst';
                break;
        }
        self::backupLog("Target archive: " . $destination, self::LOGLEVEL_DEBUG);

        $tarOptions[] = $tarVerifyOptions[] = '-f ' . escapeshellarg($destination); // Destination file


        if (!empty($containerSettings['exclude'])) {
            self::backupLog("Container got excludes! " . PHP_EOL . print_r($containerSettings['exclude'], true), self::LOGLEVEL_DEBUG);
            $excludes = explode("\r\n", $containerSettings['exclude']);
            if (!empty($excludes)) {
                foreach ($excludes as $exclude) {
                    $exclude = rtrim($exclude, "/");
                    if (!empty($exclude)) {
                        array_unshift($tarOptions, '--exclude ' . escapeshellarg($exclude)); // Add excludes to the beginning - https://unix.stackexchange.com/a/33334
                        array_unshift($tarVerifyOptions, '--exclude ' . escapeshellarg($exclude)); // Add excludes to the beginning - https://unix.stackexchange.com/a/33334
                    }
                }
            }
        }

        foreach ($volumes as $volume) {
            $tarOptions[] = $tarVerifyOptions[] = escapeshellarg($volume);
        }
        $finalTarOptions       = implode(" ", $tarOptions);
        $finalTarVerifyOptions = implode(" ", $tarVerifyOptions);

        self::backupLog("Generated tar command: " . $finalTarOptions, self::LOGLEVEL_DEBUG);
        self::backupLog("Backing up " . $container['Name'] . '...');

        $state = ABSettings::$tempFolder . '/' . ABSettings::$stateFileBackupInProgress;
        exec("tar " . $finalTarOptions . " 2>&1 & echo $! > $state && wait $!", $output, $resultcode);
        self::backupLog("Tar out: " . implode('; ', $output), self::LOGLEVEL_DEBUG);

        if ($resultcode > 0) {
            self::backupLog("tar creation failed! More output available inside debuglog, maybe.", $containerSettings['ignoreBackupErrors'] == 'yes' ? self::LOGLEVEL_INFO : self::LOGLEVEL_ERR);
            return $containerSettings['ignoreBackupErrors'] == 'yes';
        }

        self::backupLog("Backup created without issues");

        if ($containerSettings['verifyBackup'] == 'yes') {
            self::backupLog("Verifying backup...");
            self::backupLog("Final verify command: " . $finalTarVerifyOptions, self::LOGLEVEL_DEBUG);
            exec("tar " . $finalTarVerifyOptions . " 2>&1 & echo $! > $state && wait $!", $output, $resultcode);
            self::backupLog("Tar out: " . implode('; ', $output), self::LOGLEVEL_DEBUG);

            if ($resultcode > 0) {
                self::backupLog("tar verification failed! More output available inside debuglog, maybe.", $containerSettings['ignoreBackupErrors'] == 'yes' ? self::LOGLEVEL_INFO : self::LOGLEVEL_ERR);
                /**
                 * Special debug: The creation was ok but verification failed: Something is accessing docker files! List docker info for tis container
                 */
                exec("ps aux | grep docker", $output);
                self::backupLog("ps aux docker:" . PHP_EOL . print_r($output, true), self::LOGLEVEL_DEBUG);
                $nowRunning = $dockerClient->getDockerContainers();
                foreach ($nowRunning as $nowRunningContainer) {
                    if ($nowRunningContainer["Name"] == $container['Name']) {
                        self::backupLog("AFTER verify: " . print_r($nowRunningContainer, true), self::LOGLEVEL_DEBUG);
                    }
                }
                return $containerSettings['ignoreBackupErrors'] == 'yes';
            }
        }
        return true;
    }
}
