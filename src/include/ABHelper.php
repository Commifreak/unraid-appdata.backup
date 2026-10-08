<?php

namespace unraid\plugins\AppdataBackup;

require_once __DIR__ . '/ABSettings.php';
require_once __DIR__ . '/ABSnapshot.php';
require_once __DIR__ . '/ABIntegrity.php';
require_once __DIR__ . '/ABSteps.php';

/**
 * This is a helper class for some useful things
 */
class ABHelper {

    const LOGLEVEL_DEBUG = 'debug';
    const LOGLEVEL_INFO = 'info';
    const LOGLEVEL_WARN = 'warning';
    const LOGLEVEL_ERR = 'error';

    /** A container archive's extension; the rest of the file name is the container's name */
    const ARCHIVE_PATTERN = '/\.tar(\.gz|\.zst)?$/';

    /** The levels that end the script; @ cannot silence them */
    const FATAL_ERRORS = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;

    /**
     * @var array Store some temporary data about containers, which should skipped during start routine
     */
    private static $skipStartContainers = [];

    private static $emojiLevels = [
        self::LOGLEVEL_INFO => 'ℹ️',
        self::LOGLEVEL_WARN => '⚠️',
        self::LOGLEVEL_ERR  => '❌'
    ];
    public static $errorOccured = false;
    private static array $currentContainerName = [];

    public static $targetLogLevel = '';

    /** Names the run in notifications, e.g. 'Extra schedule'; '' for the main schedule */
    public static string $runLabel = '';

    /** @var resource|null The run lock from claimRun(): the kernel drops it when this process ends, however it ends */
    private static $runLock = null;

    /** Containers this run stopped (an unreadable state counts) and has not started again, as name => true, for reportCrash() */
    private static array $stoppedByRun = [];

    /**
     * Logs a message to the system log
     * @param $string
     * @return void
     */
    public static function logger($string) {
        shell_exec("logger -t 'Appdata Backup' " . escapeshellarg($string));
    }

    /** A backup destination a run can write to: an existing, writeable folder, which the plugin never creates */
    public static function destinationUsable($path) {
        return $path !== '' && is_dir($path) && is_writable($path);
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
     * @return int|bool
     */
    public static function handlePrePostScript($script, ...$args) {
        if (empty($script)) {
            self::backupLog("No " . ($args[0] ?? '') . " script set", self::LOGLEVEL_DEBUG);
            return true;
        }

        if (file_exists($script)) {
            if (!is_executable($script)) {
                self::backupLog($script . ' is not executable! Skipping!', self::LOGLEVEL_ERR);
                return false;
            }

            $arguments = '';
            foreach ($args as $arg) {
                $arguments .= ' ' . escapeshellarg($arg);
            }

            $cmd = escapeshellarg($script) . " " . $arguments;

            $output = $resultcode = null;
            self::backupLog("Executing script $cmd...");
            exec($cmd, $output, $resultcode);
            self::backupLog(self::dump($script . " CODE: " . $resultcode . ", output", $output), self::LOGLEVEL_DEBUG);
            self::backupLog("Script executed!");

            if ($resultcode != 0 && $resultcode != 2) {
                self::backupLog("Script did not return 0 (ok) or 2 (skip). It returned $resultcode!", self::LOGLEVEL_WARN);
            }
            return $resultcode;
        } else {
            self::backupLog($script . ' does not exist! Skipping!', self::LOGLEVEL_ERR);
            return false;
        }
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

        /**
         * Do not log, if the script is not running or the requesting pid is not the script pid
         */
        if (!self::scriptRunning() || self::scriptRunning() != getmypid()) {
            return;
        }

        $sectionString = '';
        foreach (self::$currentContainerName as $value) {
            if (empty($value)) {
                continue;
            }
            $sectionString .= "[$value]";
        }

        if (empty($sectionString)) {
            $sectionString = '[Main]';
        }

        $logLine = ($skipDate ? '' : "[" . date("d.m.Y H:i:s") . "][" . (self::$emojiLevels[$level] ?? $level) . "]$sectionString") . " $msg" . ($newLine ? "\n" : '');

        if ($level != self::LOGLEVEL_DEBUG) {
            file_put_contents(ABSettings::$tempFolder . '/' . ABSettings::$logfile, $logLine, FILE_APPEND);
        }
        file_put_contents(ABSettings::$tempFolder . '/' . ABSettings::$debugLogFile, $logLine, FILE_APPEND);

        if (!in_array(self::$targetLogLevel, [self::LOGLEVEL_INFO, self::LOGLEVEL_WARN, self::LOGLEVEL_ERR])) {
            return; // No notification wanted!
        }

        if ($level == self::LOGLEVEL_ERR) { // Log errors always
            self::notify("[AppdataBackup] Error!", "Please check the backup log!", "$sectionString $msg", 'alert');
        }

        if ($level == self::LOGLEVEL_WARN && self::$targetLogLevel == self::LOGLEVEL_WARN) {
            self::notify("[AppdataBackup] Warning!", "Please check the backup log!", "$sectionString $msg", 'warning');
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
        if (self::$runLabel !== '') {
            $description = self::$runLabel . ': ' . $description;
        }
        $command = '/usr/local/emhttp/webGui/scripts/notify -e ' . escapeshellarg('Appdata Backup') . ' -s ' . escapeshellarg($subject) . ' -d ' . escapeshellarg($description) . ' -m ' . escapeshellarg($message) . ' -i ' . escapeshellarg($type) . ' -l ' . escapeshellarg('/Settings/AB.Main');
        shell_exec($command);
    }

    /**
     * Stops a container
     * @param $container array
     * @return bool false if it is not backed up; it then decides whether it gets started again
     */
    public static function stopContainer($container) {
        global $dockerClient, $abSettings;

        $containerSettings = $abSettings->getContainerSpecificSettings($container['Name']);

        // Refresh the current container state; an unreadable one gets the same treatment as a failed stop
        $name      = $container['Name'];
        $container = $dockerClient->getContainerDetails($name);
        if (!is_bool($container['State']['Running'] ?? null)) {
            self::backupLog("The state of '$name' cannot be read, so it is not backed up!", self::LOGLEVEL_ERR);
            self::$skipStartContainers[] = $name;
            self::$errorOccured = true;
            return false;
        }

        // Since ->getContainerDetails return the JSON as is (and ->getDockerContainers does not allow to filter for a single one), we have to apply "Trick 17".
        $container['Running'] = $container['State']['Running'];
        $container['Paused']  = $container['State']['Paused'];
        $container['Name']    = ltrim($container['Name'], '/');

        if ($container['Running'] && !$container['Paused']) {
            self::backupLog("Stopping " . $container['Name'] . "... ", self::LOGLEVEL_INFO, false);

            if ($containerSettings['dontStop'] == 'yes') {
                self::backupLog("NOT stopping " . $container['Name'] . " because it should be backed up WITHOUT stopping!");
                self::$skipStartContainers[] = $container['Name'];
                return true;
            }

            $stopped = self::stopRunning($container['Name']);
            if ($stopped === null) {
                self::backupLog("The state of '{$container['Name']}' cannot be read after the stop, so it is not backed up! It will be started again.", self::LOGLEVEL_ERR);
                self::$errorOccured = true;
                return false;
            }
            if (!$stopped) {
                self::backupLog("'{$container['Name']}' did not stop, so it is not backed up!", self::LOGLEVEL_ERR);
                self::$skipStartContainers[] = $container['Name'];
                self::$errorOccured = true;
                return false;
            }
        } else {
            self::$skipStartContainers[] = $container['Name'];
            $state                       = "Not started!";
            if ($container['Paused']) {
                $state = "Paused!";
            }
            self::backupLog("No stopping needed for {$container['Name']}: $state");
        }
        return true;
    }

    /**
     * Stops a running container, with 'docker stop' as the fallback; also used by the restore
     * @return bool|null true once a fresh state read says it is stopped, false while it still runs, null when that read fails
     */
    public static function stopRunning($name) {
        global $dockerClient;

        // DockerClient's stop waits up to DOCKER_TIMEOUT on a socket that PHP drops after default_socket_timeout
        ini_set('default_socket_timeout', (string)max((int)ini_get('default_socket_timeout'), (int)($GLOBALS['dockercfg']['DOCKER_TIMEOUT'] ?? 10) + 30));
        $stopTimer      = time();
        $dockerStopCode = $dockerClient->stopContainer($name);
        if ($dockerStopCode != 1) {
            self::backupLog("Error while stopping container '" . $name . "'! Code: " . $dockerStopCode . " - trying 'docker stop' method", self::LOGLEVEL_WARN, true, true);
            $out = $code = null;
            exec("docker stop " . escapeshellarg($name) . " -t 30", $out, $code);
            if ($code == 0) {
                self::backupLog("That _seemed_ to work.");
            } else {
                self::backupLog("docker stop variant was unsuccessful as well when stopping '" . $name. "'! Docker said: " . implode(', ', $out), self::LOGLEVEL_ERR);
            }
        } else {
            self::backupLog("done! (took " . (time() - $stopTimer) . " seconds)", self::LOGLEVEL_INFO, true, true);
        }

        // Either stop method can report wrongly, so a fresh state read decides
        $running = $dockerClient->getContainerDetails($name)['State']['Running'] ?? null;
        if ($running !== true) {
            self::$stoppedByRun[$name] = true;
        }
        return is_bool($running) ? !$running : null;
    }

    /**
     * Starts a container
     * @param $container array
     * @return void
     */
    public static function startContainer($container) {
        global $dockerClient;

        if (in_array($container['Name'], self::$skipStartContainers)) {
            self::backupLog("Not starting " . $container['Name'] . ": this backup did not stop it.");
            return;
        }

        $dockerContainerStarted = false;
        $dockerStartTry         = 1;
        $delay                  = 0;

        // @todo: Some kind of caching?
        if (file_exists(ABSettings::$unraidAutostartFile) && $autostart = file(ABSettings::$unraidAutostartFile)) {
            foreach ($autostart as $autostartLine) {
                $line = explode(" ", trim($autostartLine));
                if ($line[0] == $container['Name'] && isset($line[1])) {
                    if (is_numeric($line[1])) {
                        $delay = (int)$line[1];
                    } else {
                        self::backupLog("Ignoring non-numeric autostart delay '{$line[1]}' for {$container['Name']}", self::LOGLEVEL_WARN);
                    }
                    break;
                }
            }
        } else {
            self::backupLog("Docker autostart file is NOT present!", self::LOGLEVEL_DEBUG);
        }

        do {
            self::backupLog("Starting {$container['Name']}... (try #$dockerStartTry) ", self::LOGLEVEL_INFO, false);
            $dockerStartCode = $dockerClient->startContainer($container['Name']);
            if ($dockerStartCode != 1) {
                if ($dockerStartCode == "Container already started") {
                    self::backupLog("Hmm - container is already started!", self::LOGLEVEL_WARN, true, true);
                    $nowRunning = $dockerClient->getDockerContainers();
                    foreach ($nowRunning as $nowRunningContainer) {
                        if ($nowRunningContainer["Name"] == $container['Name']) {
                            self::backupLog(self::dump('AFTER backing up container status', $nowRunningContainer), self::LOGLEVEL_DEBUG);
                        }
                    }
                    $dockerContainerStarted = true;
                    continue;
                }

                if (str_contains($dockerStartCode, "No such container")) {
                    self::backupLog("Container '" . $container['Name'] . "' has been removed - not starting it.", self::LOGLEVEL_INFO, true, true);
                    return;
                }

                self::backupLog("Container '" . $container['Name'] . "' did not start! - Code: " . $dockerStartCode, self::LOGLEVEL_WARN, true, true);
                if ($dockerStartTry < 3) {
                    $dockerStartTry++;
                    sleep(5);
                } else {
                    self::backupLog("Container '" . $container['Name'] . "' did not start after multiple tries, skipping. More info in debug log", self::LOGLEVEL_ERR);
                    $output = null;
                    exec("docker ps -a", $output);
                    self::backupLog(self::dump('docker ps -a', $output), self::LOGLEVEL_DEBUG);
                    break; // Exit do-while
                }
            } else {
                self::backupLog("done!", self::LOGLEVEL_INFO, true, true);
                $dockerContainerStarted = true;
            }
        } while (!$dockerContainerStarted);
        if ($dockerContainerStarted) {
            unset(self::$stoppedByRun[$container['Name']]); // before the delay, so a crash in it does not list a started container
        }
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
     * @param $reverse bool return the start order reversed (containers missing from $order come last when starting, first when stopping)
     * @return array with name as key and DockerClient info-array as value
     */
    public static function sortContainers($containers, $order, $reverse = false, $removeSkipped = true, array $group = []) {
        global $abSettings;

        // Add isGroup default to false
        foreach ($containers as $key => $container) {
            $containers[$key]['isGroup'] = false;
        }

        $_containers = array_column($containers, null, 'Name');
        if ($group) {
            $_containers = array_filter($_containers, function ($key) use ($group) {
                return in_array($key, $group);
            }, ARRAY_FILTER_USE_KEY);
        } else {
            $groups          = $abSettings->getContainerGroups();
            $appendinggroups = [];
            foreach ($groups as $groupName => $members) {
                $inRun = false;
                foreach ($members as $member) {
                    if (isset($_containers[$member])) {
                        unset($_containers[$member]);
                        $inRun = true;
                    }
                }
                if (!$inRun) {
                    continue; // e.g. the extra schedule chose none of its containers
                }
                $appendinggroups['__grp__' . $groupName] = [
                    'isGroup' => true,
                    'Name'    => $groupName
                ];
            }
            $_containers = $_containers + $appendinggroups;
        }

        $sortedContainers = [];
        foreach ($order as $name) {
            if (isset($_containers[$name])) {
                $sortedContainers[] = $_containers[$name];
                unset($_containers[$name]);
            }
        }
        $sortedContainers = array_merge($sortedContainers, $_containers);
        if ($removeSkipped) {
            // Every container, not only the ordered ones: one missing from the saved order is still skipped
            $sortedContainers = array_values(array_filter($sortedContainers, function ($container) use ($abSettings) {
                if ($container['isGroup'] || $abSettings->getContainerSpecificSettings($container['Name'])['skip'] != 'yes') {
                    return true;
                }
                self::backupLog("Not adding {$container['Name']} to sorted containers: should be ignored", self::LOGLEVEL_DEBUG);
                return false;
            }));
        }
        return $reverse ? array_reverse($sortedContainers) : $sortedContainers;
    }


    /** Volumes and tar exclude options for a container, or null if its backup is skipped */
    public static function backupPlan($container) {
        global $abSettings;

        self::backupLog(self::dump("Backup {$container['Name']} - Container Volumeinfo", $container['Volumes'] ?? []), self::LOGLEVEL_DEBUG);

        $volumes = self::getContainerVolumes($container);

        $containerSettings = $abSettings->getContainerSpecificSettings($container['Name']);

        if ($containerSettings['skipBackup'] == 'yes') {
            self::backupLog("Should NOT backup this container at all. Only include it in stop/start. Skipping backup...");
            return null;
        }

        $external = array_filter($volumes, fn($volume) => !self::isVolumeWithinAppdata($volume));
        if ($external && $containerSettings['backupExtVolumes'] == 'no') {
            self::backupLog("Leaving out external volumes (Save external volumes? is No): " . implode(', ', $external));
            $volumes = array_diff_key($volumes, $external);
        } elseif ($external) {
            self::backupLog("Including external volumes (Save external volumes? is Yes): " . implode(', ', $external));
        }

        $tarExcludes = [
            '--exclude ' . escapeshellarg('/usr/local/share/docker/tailscale_container_hook'),
            '--exclude ' . escapeshellarg('.' . ABSnapshot::PREFIX . '*'), // a leftover btrfs snapshot can sit inside a volume, see ABSnapshot::btrfsFolder()
        ];
        // getContainerVolumes already dropped an excluded volume; tar needs its path only to cut it out of a volume around it
        $mapped = array_map(fn($volume) => rtrim(explode(':', $volume)[0], '/'), $container['Volumes'] ?? []);
        if (!empty($containerSettings['exclude'])) {
            self::backupLog("Container got excludes! " . implode(", ", $containerSettings['exclude']), self::LOGLEVEL_DEBUG);
            foreach ($containerSettings['exclude'] as $exclude) {
                $exclude = rtrim($exclude, "/");
                if (!empty($exclude)) {
                    if (in_array($exclude, $mapped) && !self::isWithinVolumes($exclude, $volumes)) {
                        self::backupLog("Exclusion \"$exclude\" is a whole volume of this container, so that volume is left out");
                        continue;
                    }
                    // tar compares the text, so /mnt/user/... never matches a volume mapped as /mnt/cache/... (and the reverse)
                    if (str_starts_with($exclude, '/') && strpbrk($exclude, '*?[') === false && !self::isWithinVolumes($exclude, $volumes)) {
                        self::backupLog("Exclusion \"$exclude\" is outside every volume of this container, so it excludes nothing. Its volumes: " . implode(', ', $volumes), self::LOGLEVEL_WARN);
                    }
                    $tarExcludes[] = '--exclude ' . escapeshellarg($exclude);
                }
            }
        }

        if (!empty($abSettings->globalExclusions)) {
            self::backupLog(self::dump('Global excludes', $abSettings->globalExclusions), self::LOGLEVEL_DEBUG);
            foreach ($abSettings->globalExclusions as $globalExclusion) {
                if (in_array($globalExclusion, $mapped) && !self::isWithinVolumes($globalExclusion, $volumes)) {
                    self::backupLog("Global exclusion \"$globalExclusion\" is a whole volume of this container, so that volume is left out", self::LOGLEVEL_DEBUG);
                    continue;
                }
                $tarExcludes[] = '--exclude ' . escapeshellarg($globalExclusion);
            }
        }

        return ['volumes' => array_values($volumes), 'tarExcludes' => $tarExcludes];
    }

    private static function isWithinVolumes($path, array $volumes) {
        return (bool)array_filter($volumes, fn($volume) => str_starts_with($path, rtrim($volume, '/') . '/'));
    }

    /**
     * The heart func: take care of creating a backup!
     * @param $container array
     * @param $destination string The generated backup folder for this backup run
     * @param $plan array|null|false From backupPlan(); false works it out here
     * @return bool
     */
    public static function backupContainer($container, $destination, $plan = false) {
        global $abSettings, $dockerClient;

        if ($plan === false) {
            $plan = self::backupPlan($container);
        }
        if ($plan === null) {
            return true;
        }
        $volumes           = $plan['volumes'];
        $tarExcludes       = $plan['tarExcludes'];
        $containerSettings = $abSettings->getContainerSpecificSettings($container['Name']);

        if (empty($volumes)) {
            self::backupLog($container['Name'] . " does not have any volume to back up! Skipping. Please consider ignoring this container.", self::LOGLEVEL_WARN);
            return true;
        }

        $destination = $destination . "/" . $container['Name'] . '.tar';

        $tarVerifyOptions = array_merge($tarExcludes, ['--diff']);      // Add excludes to the beginning - https://unix.stackexchange.com/a/33334
        $tarOptions       = array_merge($tarExcludes, ['-c', '-P']);    // Add excludes to the beginning - https://unix.stackexchange.com/a/33334

        if ($abSettings->ignoreExclusionCase == 'yes') {
            $tarOptions[]       = '--ignore-case';
            $tarVerifyOptions[] = '--ignore-case';
        }

        switch ($abSettings->compression) {
            case 'yes':
                $tarOptions[] = '-z'; // GZip
                $destination  .= '.gz';
                break;
            case 'yesMulticore':
                $cpuCount     = $abSettings->compressionCpuLimit;
                $tarOptions[] = '-I "zstd -T' . $cpuCount . '"'; // zst multicore
                $destination  .= '.zst';
                break;
        }
        self::backupLog("Target archive: " . $destination, self::LOGLEVEL_DEBUG);

        $tarOptions[] = $tarVerifyOptions[] = '-f ' . escapeshellarg($destination); // Destination file

        foreach ($volumes as $volume) {
            $tarOptions[] = $tarVerifyOptions[] = escapeshellarg($volume);
        }
        $finalTarOptions       = implode(" ", $tarOptions);
        $finalTarVerifyOptions = implode(" ", $tarVerifyOptions);

        self::backupLog("Generated tar command: " . $finalTarOptions, self::LOGLEVEL_DEBUG);
        self::backupLog("Backing up " . $container['Name'] . ': ' . implode(', ', $volumes) . '...');

        $tarBackupTimer = time();

        $output = $resultcode = null;
        exec(ABSnapshot::command("tar " . $finalTarOptions, $volumes) . " 2>&1 " . ABSettings::$externalCmdPidCapture, $output, $resultcode);
        self::backupLog("Tar out: " . implode('; ', $output), self::LOGLEVEL_DEBUG);

        if (ABSnapshot::setupFailed($resultcode, $output)) {
            return false;
        }
        if ($resultcode > 0 && self::abortRequested()) {
            self::backupLog("The abort stopped tar.");
            return false;
        }
        if ($resultcode > 0) {
            self::backupLog("tar creation failed! Tar said: " . implode('; ', $output), $containerSettings['ignoreBackupErrors'] == 'yes' ? self::LOGLEVEL_INFO : self::LOGLEVEL_ERR);

            /**
             * Special debug: The creation was ok but verification failed: Something is accessing docker files! List docker info for this container
             */
            foreach ($volumes as $volume) {
                $output = null;
                exec("lsof -nl +D " . escapeshellarg($volume), $output);
                self::backupLog(self::dump("lsof($volume)", $output), self::LOGLEVEL_DEBUG);
            }

            ABIntegrity::$unverified[] = basename($destination); // kept despite the failure, so it gets no checksum
            return $containerSettings['ignoreBackupErrors'] == 'yes';
        }

        $size    = filesize($destination);
        $created = 'Archive created (' . ($size === false ? '' : self::bytes($size) . ', ') . 'took ' . self::took(time() - $tarBackupTimer) . ')';

        if (self::abortRequested()) {
            self::backupLog($created);
            return true;
        }

        if ($containerSettings['verifyBackup'] == 'yes') {
            $tarVerifyTimer = time();
            self::backupLog("$created, verifying...");
            self::backupLog("Final verify command: " . $finalTarVerifyOptions, self::LOGLEVEL_DEBUG);

            $output = $resultcode = null;
            exec(ABSnapshot::command("tar " . $finalTarVerifyOptions, $volumes) . " 2>&1 " . ABSettings::$externalCmdPidCapture, $output, $resultcode);
            self::backupLog("Tar out: " . implode('; ', $output), self::LOGLEVEL_DEBUG);

            if (ABSnapshot::setupFailed($resultcode, $output)) {
                return false;
            }
            if ($resultcode > 0 && self::abortRequested()) {
                self::backupLog("The abort stopped the verification.");
                return false;
            }
            if ($resultcode > 0) {
                self::backupLog("tar verification failed! Tar said: " . implode('; ', $output), $containerSettings['ignoreBackupErrors'] == 'yes' ? self::LOGLEVEL_INFO : self::LOGLEVEL_ERR);
                /**
                 * Special debug: The creation was ok but verification failed: Something is accessing docker files! List docker info for this container
                 */
                foreach ($volumes as $volume) {
                    $output = null;
                    exec("lsof -nl +D " . escapeshellarg($volume), $output);
                    self::backupLog(self::dump("lsof($volume)", $output), self::LOGLEVEL_DEBUG);
                }

                $nowRunning = $dockerClient->getDockerContainers();
                foreach ($nowRunning as $nowRunningContainer) {
                    if ($nowRunningContainer["Name"] == $container['Name']) {
                        self::backupLog(self::dump('AFTER verify', $nowRunningContainer), self::LOGLEVEL_DEBUG);
                    }
                }
                ABIntegrity::$unverified[] = basename($destination); // kept despite the failure, so it gets no checksum
                return $containerSettings['ignoreBackupErrors'] == 'yes';
            } else {
                self::backupLog("Verified (took " . self::took(time() - $tarVerifyTimer) . ")");
            }
        } else {
            self::backupLog($created);
            self::backupLog("Skipping verification for this container because it's not wanted!", self::LOGLEVEL_WARN);
            ABIntegrity::$unverified[] = basename($destination);
        }
        return true;
    }

    /**
     * Writes the flash drive backup zip into $destination
     * @param $destination string
     * @param $script string Unraid's flash_backup script
     * @return bool
     */
    public static function backupFlash($destination, $script = '/usr/local/emhttp/webGui/scripts/flash_backup') {
        global $abSettings;

        if (!file_exists($script)) {
            self::backupLog("The flash backup script is not available!", self::LOGLEVEL_ERR);
            return false;
        }

        $docroot = '/usr/local/emhttp';
        $vars    = parse_ini_file(ABSettings::$emhttpVars) ?: [];
        $server  = str_replace(' ', '_', strtolower($vars['NAME'] ?? 'tower'));
        $name    = "$server-v" . ($vars['version'] ?? 'unknown') . "-boot-backup-" . date('Ymd-Hi') . ".zip";
        $target  = $destination . '/' . $name;

        // Unraid 7.4+ streams the zip to stdout; older releases write it elsewhere and print its file name.
        // stdout is the zip, so the script's errors go to a file of their own
        $errFile = tempnam(ABSettings::$tempFolder, 'flash_backup_err_');
        $output  = $resultcode = null;
        exec(escapeshellarg($script) . ' > ' . escapeshellarg($target) . ($errFile ? ' 2> ' . escapeshellarg($errFile) : '') . ' ' . ABSettings::$externalCmdPidCapture, $output, $resultcode);
        $scriptSaid = $errFile ? trim((string)@file_get_contents($errFile, false, null, 0, 4096)) : '';
        $scriptSaid = $scriptSaid === '' ? '' : " Script said: " . str_replace("\n", '; ', $scriptSaid);
        if ($errFile) {
            @unlink($errFile);
        }

        if (!is_file($target)) {
            self::backupLog("Flash backup failed: cannot write to the destination!", self::LOGLEVEL_ERR);
            return false;
        }

        if (file_get_contents($target, false, null, 0, 4) === "PK\x03\x04") {
            if ($resultcode != 0) {
                @unlink($target);
                self::backupLog("Flash backup failed: the flash backup script returned $resultcode!" . $scriptSaid, self::LOGLEVEL_ERR);
                return false;
            }
        } else {
            // The file name is the last line; read only the start in case the script printed something large.
            $lines   = preg_split('/\R/', trim((string)file_get_contents($target, false, null, 0, 4096)));
            $printed = trim(end($lines));
            unlink($target);
            self::backupLog("flash backup returned: " . $printed, self::LOGLEVEL_DEBUG);
            if ($printed === '') {
                self::backupLog("Flash backup failed: no answer from script!" . $scriptSaid, self::LOGLEVEL_ERR);
                return false;
            }
            if (!preg_match('/\A[A-Za-z0-9_.-]+-(flash|boot)-backup-[0-9-]+\.zip\z/', $printed)) {
                self::backupLog("Flash backup failed: unexpected answer from script! See debug log." . $scriptSaid, self::LOGLEVEL_ERR);
                return false;
            }

            $copied = copy($docroot . '/' . $printed, $destination . '/' . $printed);
            // Following is from Download.php
            if (($backup = readlink($docroot . '/' . $printed)) && basename($backup) === $printed) {
                unlink($backup);
            }
            @unlink($docroot . '/' . $printed);
            if (!$copied) {
                self::backupLog("Copying flash backup to destination failed!", self::LOGLEVEL_ERR);
                return false;
            }
            $name   = $printed;
            $target = $destination . '/' . $printed;
        }

        // unzip reads every entry, so a damaged zip fails the run now instead of at restore time
        $output = $resultcode = null;
        exec('unzip -tq ' . escapeshellarg($target) . ' 2>&1 ' . ABSettings::$externalCmdPidCapture, $output, $resultcode);
        if (self::abortRequested()) {
            return false;
        }
        if ($resultcode == 127) {
            self::backupLog("unzip is not available, so the flash backup was not tested.", self::LOGLEVEL_WARN);
        } elseif ($resultcode > 1) {
            self::backupLog("Flash backup failed: the zip is damaged! unzip said: " . implode('; ', $output), self::LOGLEVEL_ERR);
            return false;
        }

        self::backupLog("Flash backup created!");
        if (!empty($abSettings->flashBackupCopy)) {
            self::backupLog("Copying the flash backup to '{$abSettings->flashBackupCopy}' as well...");
            if (!copy($target, $abSettings->flashBackupCopy . '/' . $name)) {
                self::backupLog("Copying the flash backup to '{$abSettings->flashBackupCopy}' FAILED!", self::LOGLEVEL_ERR);
            }
        }
        return true;
    }

    /** Starts a run: takes the run lock, clears the last run's logs and abort request, records this process; false, with a notification, when the lock file cannot be opened or another backup, restore or check holds the lock ($wait: for up to 12 hours) */
    public static function claimRun($wait = false) {
        $lockFile = ABSettings::$tempFolder . '/' . ABSettings::$stateFileLock;
        $lock     = @fopen($lockFile, 'ce'); // 'e': commands this run starts must not inherit the lock
        if ($lock === false) {
            self::notify("[AppdataBackup] Error!", "Cannot start", "Could not open the run lock '$lockFile'.", 'alert');
            return false;
        }
        $giveUp = time() + 12 * 3600;
        while (!flock($lock, LOCK_EX | LOCK_NB)) {
            if (!$wait || time() >= $giveUp) {
                self::notify("Appdata Backup", "Still running", "There is something running already.");
                return false;
            }
            sleep(30);
        }
        self::$runLock = $lock;
        register_shutdown_function([self::class, 'reportCrash']);
        if (file_exists(ABSettings::$tempFolder . '/' . ABSettings::$stateFileAbort)) {
            unlink(ABSettings::$tempFolder . '/' . ABSettings::$stateFileAbort);
        }
        exec("rm -f " . escapeshellarg(ABSettings::$tempFolder) . "/*.log");
        file_put_contents(ABSettings::$tempFolder . '/' . ABSettings::$stateFileScriptRunning, getmypid());
        return true;
    }

    /**
     * Checks, if backup/restore is running
     * @param $externalCmd bool Check external commands (tar or something else) which was started by backup/restore?
     * @return array|false|string|string[]|null
     */
    public static function scriptRunning($externalCmd = false) {
        $filePath = ABSettings::$tempFolder . '/' . ($externalCmd ? ABSettings::$stateExtCmd : ABSettings::$stateFileScriptRunning);
        $pid      = file_exists($filePath) ? file_get_contents($filePath) : false;
        if (!$pid) {
            // lockfile not there: process not running anymore
            return false;
        }
        $pid = preg_replace("/\D/", '', $pid); // Filter any non digit characters.
        if (file_exists('/proc/' . $pid)) {
            return $pid;
        } else {
            unlink($filePath); // Remove dead state file
            return false;
        }
    }

    /** What the job holding the lock is doing, from its script name, for the buttons it blocks */
    public static function runningJob($pid) {
        $args   = explode("\0", (string)@file_get_contents("/proc/$pid/cmdline"));
        $script = current(array_filter($args, fn($arg) => str_ends_with($arg, '.php')));
        if (basename((string)$script) === 'backup.php' && in_array('extra', $args, true)) {
            return 'Extra schedule backup in progress';
        }
        return ['backup.php' => 'Backup in progress', 'restore.php' => 'Restore in progress', 'verify.php' => 'Checksum check in progress'][basename((string)$script)] ?? 'Another job is running';
    }

    /**
     * @return bool
     */
    public static function abortRequested() {
        return file_exists(ABSettings::$tempFolder . '/' . ABSettings::$stateFileAbort);
    }

    /**
     * Helper, to get all host paths of a container
     * @param $container
     * @return array
     */
    public static function getContainerVolumes($container, $skipExclusionCheck = false) {
        global $abSettings;

        $volumes  = [];
        $excluded = [];
        foreach ($container['Volumes'] ?? [] as $volume) {
            $hostPath = rtrim(explode(":", $volume)[0], '/');
            if (empty($hostPath)) {
                self::backupLog("This volume is empty (rootfs mapped??)! Ignoring.", self::LOGLEVEL_DEBUG);
                continue;
            }

            if (!$skipExclusionCheck) {
                $containerSettings = $abSettings->getContainerSpecificSettings($container['Name']);

                if (in_array($hostPath, $containerSettings['exclude'])) {
                    self::backupLog("Ignoring '$hostPath' because it's listed in the container's exclusions list!", self::LOGLEVEL_DEBUG);
                    $excluded[] = $hostPath;
                    continue;
                }

                if (in_array($hostPath, $abSettings->globalExclusions)) {
                    self::backupLog("Ignoring '$hostPath' because it's listed in the global exclusions list!", self::LOGLEVEL_DEBUG);
                    $excluded[] = $hostPath;
                    continue;
                }
            }

            if (!str_starts_with($hostPath, '/')) {
                self::backupLog("'$hostPath' is a Docker volume, not a folder on the host, so it is not backed up.", self::LOGLEVEL_WARN);
                continue;
            }

            if (!file_exists($hostPath)) {
                self::backupLog("'$hostPath' does NOT exist! Please check your mappings! Skipping it for now.", self::LOGLEVEL_ERR);
                continue;
            }
            if (in_array($hostPath, $abSettings->allowedSources)) {
                self::backupLog("Removing container mapping \"$hostPath\" because it is a source path (exact match)!");
                continue;
            }
            $volumes[] = $hostPath;
        }

        // Excluded volumes join the nesting check, so volumes inside them go too: the settings page lists only the outer one
        $volumes = array_unique(array_merge($volumes, $excluded)); // Remove duplicate Array values => https://forums.unraid.net/topic/137710-plugin-appdatabackup/?do=findComment&comment=1256267

        usort($volumes, function ($a, $b) {
            return strlen($a) <=> strlen($b);
        });
        self::backupLog(self::dump('sorted volumes', $volumes), self::LOGLEVEL_DEBUG);

        /**
         * Check volumes against nesting
         * Maybe someone has a better idea how to solve it efficiently?
         */
        foreach ($volumes as $volume) {
            foreach ($volumes as $key2 => $volume2) {
                if ($volume !== $volume2 && self::isVolumeWithinAppdata($volume) && str_starts_with($volume2, $volume . '/')) { // Trailing slash assures whole directory name => https://forums.unraid.net/topic/136995-pluginbeta-appdatabackup/?do=findComment&comment=1255260
                    self::backupLog("'$volume2' is within mapped volume '$volume'! Ignoring!");
                    unset($volumes[$key2]);
                }
            }
        }
        return array_diff($volumes, $excluded);
    }

    /**
     * Is a given volume internal or external mapping?
     * @param $volume
     * @return bool
     */
    public static function isVolumeWithinAppdata($volume) {
        global $abSettings;
        static $logged = []; // the nesting check calls this for every pair of volumes

        foreach ($abSettings->allowedSources as $appdataPath) {
            if (str_starts_with($volume, $appdataPath . '/')) { // Add trailing slash to get exact match! Assures whole dir name!
                if (!isset($logged[$volume])) {
                    self::backupLog("Volume '$volume' IS within AppdataPath '$appdataPath'!", self::LOGLEVEL_DEBUG);
                    $logged[$volume] = true;
                }
                return true;
            }
        }
        return false;
    }

    /** $seconds as "3 s", "4 min 12 s" or "1 h 2 min" */
    public static function took($seconds) {
        if ($seconds < 60) {
            return "$seconds s";
        }
        return $seconds < 3600 ? intdiv($seconds, 60) . ' min ' . ($seconds % 60) . ' s' : intdiv($seconds, 3600) . ' h ' . intdiv($seconds % 3600, 60) . ' min';
    }

    public static function bytes($bytes) {
        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $unit) {
            // 1000s, as Unraid's own pages count
            if ($bytes < 1000 || $unit === 'TB') {
                return round($bytes, $unit === 'B' ? 0 : 1) . ' ' . $unit;
            }
            $bytes /= 1000;
        }
    }

    /** "$label:" then $value one field per line, nested values indented below: print_r's layout without its brackets */
    public static function dump($label, $value) {
        return $label . ':' . self::dumpValue($value, '  ');
    }

    private static function dumpValue($value, $indent) {
        if (!is_array($value) || $value === []) {
            return ' ' . match (true) {
                $value === true => 'yes',
                $value === false => 'no',
                $value === null, $value === '', $value === [] => '-',
                default => (string)$value,
            };
        }
        $lines = '';
        foreach ($value as $key => $item) {
            $lines .= PHP_EOL . $indent . (array_is_list($value) ? '-' : "$key:") . self::dumpValue($item, $indent . '  ');
        }
        return $lines;
    }

    /** $path with glob()'s metacharacters escaped, so a [, ? or * in a folder name matches only itself */
    public static function globQuote($path) {
        return addcslashes($path, '\\*?[');
    }

    public static function errorHandler(int $errno, string $errstr, string $errfile, int $errline, array $errcontext = []): bool {
        // @ leaves only fatal levels in error_reporting(). Unraid's php.ini leaves out E_WARNING, so never test $errno against it.
        if ((error_reporting() & ~self::FATAL_ERRORS) === 0) {
            return false;
        }
        $errStr = "got PHP error: $errno / $errstr $errfile:$errline with context: " . json_encode($errcontext);
        file_put_contents("/tmp/appdata.backup_phperr", $errStr . PHP_EOL, FILE_APPEND);
        self::backupLog("PHP-ERROR occurred! $errno / $errstr $errfile:$errline", self::LOGLEVEL_DEBUG);

        return true;
    }

    /** Shutdown function from claimRun(): a fatal error skips the scripts' own ending, so the run reports it here */
    public static function reportCrash() {
        $error = error_get_last();
        if (!$error || !($error['type'] & self::FATAL_ERRORS)) {
            return; // error_get_last() also holds a warning left by @
        }
        $stopped = self::$stoppedByRun ? ' Still stopped by this run: ' . implode(', ', array_keys(self::$stoppedByRun)) . '.' : '';
        self::backupLog("The run crashed with a PHP error: " . strtok($error['message'], "\n") . '.' . $stopped, self::LOGLEVEL_ERR);
        self::backupLog(self::dump('PHP error', $error), self::LOGLEVEL_DEBUG);
    }

    /** Installs the update planned for $name, unless its backup just failed: then there would be no fresh backup to go back to */
    private static function updateAfterBackup($name, $backedUp) {
        global $dockerUpdateList;
        if (!in_array($name, $dockerUpdateList)) {
            return;
        }
        if (!$backedUp) {
            self::backupLog("Not updating $name: its backup failed, so there would be no fresh backup to go back to.", self::LOGLEVEL_WARN);
            return;
        }
        self::updateContainer($name);
    }

    public static function updateContainer($name) {
        global $abSettings, $dockerClient;
        self::backupLog("Installing planned update for $name...");
        exec('/usr/local/emhttp/plugins/dynamix.docker.manager/scripts/update_container ' . escapeshellarg($name));

        // update_container removes and recreates the container, so a missing one means the recreate failed.
        $dockerClient->flushCaches();
        if (!$dockerClient->doesContainerExist($name)) {
            self::backupLog("Updating '$name' failed: the container no longer exists!", self::LOGLEVEL_ERR);
            return;
        }

        if ($abSettings->updateLogWanted == 'yes') {
            self::notify("Appdata Backup", "Container '$name' updated!", "Container '$name' was successfully updated during this backup run!");
        }
    }

    /** Starts containers and group members in start order; false if an abort was requested */
    private static function startContainers($containers) {
        self::backupLog("Set containers to previous state");
        foreach ($containers as $_container) {
            $resolvedContainer = self::resolveContainer($_container);
            foreach (($resolvedContainer !== false ? $resolvedContainer : [$_container]) as $container) {
                self::setCurrentContainerName($container);
                ABSteps::detail($container['Name']);
                self::startContainer($container);

                if (self::abortRequested()) {
                    return false;
                }
            }
            self::setCurrentContainerName($_container, true);
        }

        self::setCurrentContainerName(null);
        return true;
    }

    public static function doBackupMethod($method, $containerListOverride = null) {
        global $abSettings, $dockerContainers, $sortedStopContainers, $sortedStartContainers, $abDestination;

        self::backupLog(__METHOD__ . ': $containerListOverride: ' . implode(', ', array_column(($containerListOverride ?? []), 'Name')), self::LOGLEVEL_DEBUG);

        switch ($method) {
            case 'stopAll':

                $backupOrder = $containerListOverride ? array_reverse($containerListOverride) : $sortedStopContainers;
                $startOrder  = $containerListOverride ?: $sortedStartContainers;
                $plans       = [];
                $started     = false;
                $skipped     = [];
                $top         = $containerListOverride === null; // a group in one-after-the-other runs this case nested, without steps
                $queued      = 0;

                self::backupLog("Method: Stop all containers before continuing.");
                if ($top) {
                    ABSteps::start('Stopping containers');
                }
                foreach ($backupOrder as $_container) {
                    $resolvedContainer = self::resolveContainer($_container, true);
                    foreach (($resolvedContainer !== false ? $resolvedContainer : [$_container]) as $container) {
                        self::setCurrentContainerName($container);
                        ABSteps::detail($container['Name']);
                        $preContainerRet = ABHelper::handlePrePostScript($abSettings->preContainerBackupScript, 'pre-container', $container['Name']);
                        if ($preContainerRet === 2) {
                            self::backupLog("preContainer script decided to skip backup.");
                            $skipped[]                   = $container['Name'];
                            self::$skipStartContainers[] = $container['Name']; // never stopped, so not started either
                            self::setCurrentContainerName($container, true);
                            continue;
                        }
                        if (!self::stopContainer($container)) {
                            $skipped[] = $container['Name'];
                            self::setCurrentContainerName($container, true);
                            continue;
                        }
                        $queued++;
                        if ($abSettings->snapshotMode == 'yes') {
                            $plans[$container['Name']] = self::backupPlan($container);
                        }

                        if (self::abortRequested()) {
                            return false;
                        }
                    }
                    self::setCurrentContainerName($_container, true);
                }

                self::setCurrentContainerName(null);

                if (self::abortRequested()) {
                    return false;
                }

                if ($plans) {
                    $volumes = array_merge(...array_column(array_filter($plans), 'volumes'));
                    if ($volumes && ABSnapshot::create($volumes)) {
                        self::backupLog("Snapshots taken - starting the containers before the backup.");
                        if ($top) {
                            ABSteps::start('Starting containers');
                        }
                        if (!self::startContainers($startOrder)) {
                            return false;
                        }
                        $started = true;
                    } elseif ($volumes) {
                        self::backupLog("Snapshots are not possible for this run - backing up with the containers stopped.", self::LOGLEVEL_WARN);
                    }
                }

                if ($top) {
                    if (!$started) {
                        ABSteps::startContainersLast();
                    }
                    ABSteps::start('Backing up containers');
                } else {
                    self::backupLog("Starting backup for containers");
                }
                $done = 0;
                foreach ($backupOrder as $_container) {
                    $resolvedContainer = self::resolveContainer($_container, true);
                    foreach (($resolvedContainer !== false ? $resolvedContainer : [$_container]) as $container) {
                        self::setCurrentContainerName($container);
                        if (in_array($container['Name'], $skipped)) {
                            self::setCurrentContainerName($container, true);
                            continue;
                        }
                        ABSteps::detail($container['Name'] . ', ' . ++$done . ' of ' . $queued);

                        $backedUp = self::backupContainer($container, $abDestination, array_key_exists($container['Name'], $plans) ? $plans[$container['Name']] : false);
                        if (!$backedUp) {
                            self::$errorOccured = true;
                        }

                        ABHelper::handlePrePostScript($abSettings->postContainerBackupScript, 'post-container', $container['Name']);

                        if (self::abortRequested()) {
                            return false;
                        }

                        self::updateAfterBackup($container['Name'], $backedUp);
                    }
                    self::setCurrentContainerName($_container, true);
                }

                self::setCurrentContainerName(null);
                ABSnapshot::destroyAll();

                if (self::abortRequested()) {
                    return false;
                }

                self::handlePrePostScript($abSettings->postBackupScript, 'post-backup', $abDestination);

                if (self::abortRequested()) {
                    return false;
                }

                if (!$started) {
                    if ($top) {
                        ABSteps::start('Starting containers');
                    }
                    if (!self::startContainers($startOrder)) {
                        return false;
                    }
                }

                break;
            case 'oneAfterTheOther':
                self::backupLog("Method: Stop/Backup/Start");
                if ($containerListOverride === null) {
                    ABSteps::start('Backing up containers');
                }

                if (self::abortRequested()) {
                    return false;
                }

                $list = $containerListOverride ?: $sortedStopContainers;
                $done = 0;
                foreach ($list as $container) {
                    ABSteps::detail($container['Name'] . ', ' . ++$done . ' of ' . count($list));

                    if ($container['isGroup']) {
                        $groupContainers = self::resolveContainer($container);
                        if (!empty($groupContainers)) {
                            self::doBackupMethod('stopAll', $groupContainers);
                            self::setCurrentContainerName($container, true);
                        }
                        continue;
                    }

                    self::setCurrentContainerName($container);
                    $preContainerRet = ABHelper::handlePrePostScript($abSettings->preContainerBackupScript, 'pre-container', $container['Name']);
                    if ($preContainerRet === 2) {
                        self::backupLog("preContainer script decided to skip backup.");
                        self::setCurrentContainerName($container, true);
                        continue;
                    }

                    if (!self::stopContainer($container)) {
                        self::startContainer($container); // starts only one whose state was unreadable after the stop; stopContainer() marked the rest
                        self::setCurrentContainerName($container, true);
                        continue;
                    }

                    if (self::abortRequested()) {
                        return false;
                    }

                    $plan     = self::backupPlan($container);
                    $snapshot = $abSettings->snapshotMode == 'yes' && !empty($plan['volumes']) && ABSnapshot::create($plan['volumes']);
                    if ($snapshot) {
                        self::backupLog("Snapshot taken - starting the container before the backup.");
                        self::startContainer($container);
                    }

                    $backedUp = self::backupContainer($container, $abDestination, $plan);
                    if (!$backedUp) {
                        self::$errorOccured = true;
                    }

                    ABHelper::handlePrePostScript($abSettings->postContainerBackupScript, 'post-container', $container['Name']);
                    ABSnapshot::destroyAll();

                    if (self::abortRequested()) {
                        return false;
                    }

                    self::updateAfterBackup($container['Name'], $backedUp);

                    if (self::abortRequested()) {
                        return false;
                    }

                    if (!$snapshot) {
                        self::startContainer($container);
                    }

                    if (self::abortRequested()) {
                        return false;
                    }
                    self::setCurrentContainerName($container, true);
                }
                self::handlePrePostScript($abSettings->postBackupScript, 'post-backup', $abDestination);

                break;
        }
        return true;
    }

    /**
     * resolves a container group. Special note goes to the param $reverse: We MUST reverse if the input order is NOT start-order oriented!
     * @param $container
     * @param $reverse
     * @return array|false
     */
    public static function resolveContainer($container, $reverse = false) {
        global $dockerContainers, $abSettings;
        if ($container['isGroup']) {
            self::setCurrentContainerName($container);
            $groupMembers = $abSettings->getContainerGroups($container['Name']);
            self::backupLog("Reached a group: " . $container['Name'], self::LOGLEVEL_DEBUG);
            $sortedGroupContainers = self::sortContainers($dockerContainers, $abSettings->containerGroupOrder[$container['Name']], $reverse, true, $groupMembers);
            self::backupLog("Containers in this group: " . implode(', ', array_column($sortedGroupContainers, 'Name')), self::LOGLEVEL_DEBUG);
            return $sortedGroupContainers;
        }
        return false;
    }

    public static function setCurrentContainerName($container, $remove = false) {
        if (empty($container)) {
            self::$currentContainerName = [];
            return;
        }

        if (empty(self::$currentContainerName) && !$remove) {
            self::$currentContainerName = $container['isGroup'] ? [$container['Name'], ''] : [$container['Name']];
            return;
        }

        if ($remove) {
            if (count(self::$currentContainerName) > 1) {
                $lastKey = array_key_last(self::$currentContainerName);
                if ($container['isGroup']) {
                    unset(self::$currentContainerName[$lastKey - 1]);
                } else {
                    self::$currentContainerName[$lastKey] = '';
                }

            } else {
                self::$currentContainerName = [];
            }

        } else {
            if ($container['isGroup']) {
                $lastElem                     = array_pop(self::$currentContainerName);
                self::$currentContainerName[] = $container['Name'];
                self::$currentContainerName[] = $lastElem;
            } else {
                $lastKey                              = array_key_last(self::$currentContainerName);
                self::$currentContainerName[$lastKey] = $container['Name'];
            }
        }

        self::$currentContainerName = array_values(self::$currentContainerName);
    }
}
