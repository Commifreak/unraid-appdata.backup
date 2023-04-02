<?php

namespace unraid\plugins\AppdataBackup;

require_once __DIR__ . '/ABSettings.php';

/**
 * This is a helper class for some useful things
 */
class ABHelper {

    const LOGLEVEL_DEBUG = 'debug';
    const LOGLEVEL_INFO = 'info';
    const LOGLEVEL_WARN = 'warning';
    const LOGLEVEL_ERR = 'error';

    /**
     * @var array Store some temporary data about containers, which should skipped during start routine
     */
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

    /**
     * Takes care of every hook script execution
     * @param $script string
     * @return bool
     */
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

            $output = $resultcode = null;
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

        /**
         * Do not log, if the script is not running
         */
        if (!self::scriptRunning()) {
            return;
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

    /**
     * Stops a container
     * @param $container array
     * @return true|void
     */
    public static function stopContainer($container) {
        global $dockerClient, $abSettings;

        $containerSettings = $abSettings->getContainerSpecificSettings($container['Name']);
        if ($containerSettings['dontStop'] == 'yes') {
            self::backupLog("NOT stopping " . $container['Name'] . " because it should be backed up WITHOUT stopping!", self::LOGLEVEL_WARN);
            self::$skipStartContainers[] = $container['Name'];
            return true;
        }

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

    /**
     * Starts a container
     * @param $container array
     * @return void
     */
    public static function startContainer($container) {
        global $dockerClient;

        if (in_array($container['Name'], self::$skipStartContainers)) {
            self::backupLog($container['Name'] . " is being ignored, because it was not started before (or should not be started).");
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


    /**
     * The heart func: take care of creating a backup!
     * @param $container array
     * @param $destination string The generated backup folder for this backup run
     * @return bool
     */
    public static function backupContainer($container, $destination) {
        global $abSettings, $dockerClient;

        $containerSettings = $abSettings->getContainerSpecificSettings($container['Name']);

        self::backupLog("Backup {$container['Name']} - Container Volumeinfo: " . print_r($container['Volumes'], true), self::LOGLEVEL_DEBUG);

        $volumes = self::getContainerVolumes($container);

        if ($containerSettings['backupExtVolumes'] == 'no') {
            self::backupLog("Should NOT backup ext volumes, sanitizing...", self::LOGLEVEL_DEBUG);
            foreach ($volumes as $index => $volume) {
                if (!self::isVolumeWithinAppdata($volume)) {
                    unset($volumes[$index]);
                }
            }
        }

        if (empty($volumes)) {
            self::backupLog($container['Name'] . " does not have any volume to back up! Skipping");
            return true;
        }

        self::backupLog("Final volumes: " . implode(", ", $volumes), self::LOGLEVEL_DEBUG);

        $destination = $destination . "/" . $container['Name'] . '.tar';

        $tarVerifyOptions = ['--diff'];
        $tarOptions       = ['-c', '-P'];

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

        $output = $resultcode = null;
        exec("tar " . $finalTarOptions . " 2>&1 " . ABSettings::$externalCmdPidCapture, $output, $resultcode);
        self::backupLog("Tar out: " . implode('; ', $output), self::LOGLEVEL_DEBUG);

        if ($resultcode > 0) {
            self::backupLog("tar creation failed! More output available inside debuglog, maybe.", $containerSettings['ignoreBackupErrors'] == 'yes' ? self::LOGLEVEL_INFO : self::LOGLEVEL_ERR);
            return $containerSettings['ignoreBackupErrors'] == 'yes';
        }

        self::backupLog("Backup created without issues");

        if (ABHelper::abortRequested()) {
            return true;
        }

        if ($containerSettings['verifyBackup'] == 'yes') {
            self::backupLog("Verifying backup...");
            self::backupLog("Final verify command: " . $finalTarVerifyOptions, self::LOGLEVEL_DEBUG);

            $output = $resultcode = null;
            exec("tar " . $finalTarVerifyOptions . " 2>&1 " . ABSettings::$externalCmdPidCapture, $output, $resultcode);
            self::backupLog("Tar out: " . implode('; ', $output), self::LOGLEVEL_DEBUG);

            if ($resultcode > 0) {
                self::backupLog("tar verification failed! More output available inside debuglog, maybe.", $containerSettings['ignoreBackupErrors'] == 'yes' ? self::LOGLEVEL_INFO : self::LOGLEVEL_ERR);
                /**
                 * Special debug: The creation was ok but verification failed: Something is accessing docker files! List docker info for this container
                 */
                foreach ($volumes as $volume) {
                    $output = null;
                    exec("lsof -nl +D " . escapeshellarg($volume), $output);
                    self::backupLog("lsof($volume)" . PHP_EOL . print_r($output, true), self::LOGLEVEL_DEBUG);
                }

                $nowRunning = $dockerClient->getDockerContainers();
                foreach ($nowRunning as $nowRunningContainer) {
                    if ($nowRunningContainer["Name"] == $container['Name']) {
                        self::backupLog("AFTER verify: " . print_r($nowRunningContainer, true), self::LOGLEVEL_DEBUG);
                    }
                }
                return $containerSettings['ignoreBackupErrors'] == 'yes';
            }
        } else {
            self::backupLog("Skipping verification for this container because its not wanted!", self::LOGLEVEL_WARN);
        }
        return true;
    }

    /**
     * Checks, if backup/restore is running
     * @param $externalCmd bool Check external commands (tar or something else) which was started by backup/restore?
     * @return array|false|string|string[]|null
     */
    public static function scriptRunning($externalCmd = false) {
        $pid = @file_get_contents(ABSettings::$tempFolder . '/' . ($externalCmd ? ABSettings::$stateExtCmd : ABSettings::$stateFileScriptRunning));
        if (!$pid) {
            // lockfile not there: process not running anymore
            return false;
        }
        $pid = preg_replace("/\D/", '', $pid); // Filter any non digit characters.
        if (file_exists('/proc/' . $pid)) {
            return $pid;
        } else {
            @unlink(ABSettings::$tempFolder . '/' . ($externalCmd ? ABSettings::$stateExtCmd : ABSettings::$stateFileScriptRunning)); // Remove dead state file
            return false;
        }
    }

    /**
     * @return bool
     * @todo: register_shutdown_function? in beiden Scripts? Damit kill und goto :end?
     */
    public static function abortRequested() {
        return file_exists(ABSettings::$tempFolder . '/' . ABSettings::$stateFileAbort);
    }

    /**
     * Helper, to get all host paths of a container
     * @param $container
     * @return array
     */
    public static function getContainerVolumes($container) {

        $volumes = [];
        foreach ($container['Volumes'] ?? [] as $volume) {
            $hostPath  = explode(":", $volume)[0];
            $volumes[] = rtrim($hostPath, '/');
        }
        return $volumes;
    }

    /**
     * Is a given volume internal or external mapping?
     * @param $volume
     * @return bool
     */
    public static function isVolumeWithinAppdata($volume) {
        global $abSettings;

        foreach ($abSettings->allowedSources as $appdataPath) {
            $appdataPath = rtrim($appdataPath, '/');
            if (str_starts_with($volume, $appdataPath)) {
                self::backupLog(__METHOD__ . ": $appdataPath IS within $volume.", self::LOGLEVEL_DEBUG);
                return true;
            }
        }
        return false;
    }

    public static function errorHandler(int $errno, string $errstr, string $errfile, int $errline): bool {
        self::notify("Appdata Backup PHP error", "Appdata Backup PHP error", "got PHP error: $errno / $errstr $errfile:$errline", 'alert');
        self::backupLog("got PHP error: $errno / $errstr $errfile:$errline", self::LOGLEVEL_ERR);

        return true;
    }
}
