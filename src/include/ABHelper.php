<?php

namespace unraid\plugins\AppdataBackup;

require_once __DIR__ . '/ABSettings.php';
require_once __DIR__ . '/ABSnapshot.php';

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

    private static $emojiLevels = [
        self::LOGLEVEL_INFO => 'ℹ️',
        self::LOGLEVEL_WARN => '⚠️',
        self::LOGLEVEL_ERR  => '❌'
    ];
    public static $errorOccured = false;
    private static array $currentContainerName = [];

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
     * @return int|bool
     */
    public static function handlePrePostScript($script, ...$args) {
        if (empty($script)) {
            self::backupLog("Not executing script: Not set!", self::LOGLEVEL_DEBUG);
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
            self::backupLog($script . " CODE: " . $resultcode . " - " . print_r($output, true), self::LOGLEVEL_DEBUG);
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
            self::notify("[AppdataBackup] Error!", "Please check the backup log!", $msg, 'alert');
        }

        if ($level == self::LOGLEVEL_WARN && self::$targetLogLevel == self::LOGLEVEL_WARN) {
            self::notify("[AppdataBackup] Warning!", "Please check the backup log!", $msg, 'warning');
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
        $command = '/usr/local/emhttp/webGui/scripts/notify -e ' . escapeshellarg('Appdata Backup') . ' -s ' . escapeshellarg($subject) . ' -d ' . escapeshellarg($description) . ' -m ' . escapeshellarg($message) . ' -i ' . escapeshellarg($type) . ' -l ' . escapeshellarg('/Settings/AB.Main');
        shell_exec($command);
    }

    /**
     * Stops a container
     * @param $container array
     * @return bool false if the container is still running after the stop attempts
     */
    public static function stopContainer($container) {
        global $dockerClient, $abSettings;

        $containerSettings = $abSettings->getContainerSpecificSettings($container['Name']);

        // Refresh the current container state
        $container = $dockerClient->getContainerDetails($container['Name']);

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

            $stopTimer      = time();
            $dockerStopCode = $dockerClient->stopContainer($container['Name']);
            if ($dockerStopCode != 1) {
                self::backupLog("Error while stopping container '" . $container['Name'] . "'! Code: " . $dockerStopCode . " - trying 'docker stop' method", self::LOGLEVEL_WARN, true, true);
                $out = $code = null;
                exec("docker stop " . escapeshellarg($container['Name']) . " -t 30", $out, $code);
                if ($code == 0) {
                    self::backupLog("That _seemed_ to work.");
                } else {
                    self::backupLog("docker stop variant was unsuccessful as well when stopping '" . $container['Name']. "'! Docker said: " . implode(', ', $out), self::LOGLEVEL_ERR);
                }
            } else {
                self::backupLog("done! (took " . (time() - $stopTimer) . " seconds)", self::LOGLEVEL_INFO, true, true);
            }

            // Either stop method can report wrongly, so a fresh state read decides; an unreadable state counts as running
            if (($dockerClient->getContainerDetails($container['Name'])['State']['Running'] ?? null) !== false) {
                self::backupLog("'{$container['Name']}' did not stop (its state is running or unreadable), so it is not backed up!", self::LOGLEVEL_ERR);
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
     * Starts a container
     * @param $container array
     * @return void
     */
    public static function startContainer($container) {
        global $dockerClient;

        if (in_array($container['Name'], self::$skipStartContainers)) {
            self::backupLog("Starting " . $container['Name'] . " is being ignored, because it was not started before (or should not be started).");
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
                            self::backupLog("AFTER backing up container status: " . print_r($nowRunningContainer, true), self::LOGLEVEL_DEBUG);
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
                    self::backupLog("docker ps -a:" . PHP_EOL . print_r($output, true), self::LOGLEVEL_DEBUG);
                    break; // Exit do-while
                }
            } else {
                self::backupLog("done!", self::LOGLEVEL_INFO, true, true);
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
                foreach ($members as $member) {
                    if (isset($_containers[$member])) {
                        unset($_containers[$member]);
                    }
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
            if (!str_starts_with($name, '__grp__')) {
                $containerSettings = $abSettings->getContainerSpecificSettings($name, $removeSkipped);
                if ($containerSettings['skip'] == 'yes' && $removeSkipped) {
                    self::backupLog("Not adding $name to sorted containers: should be ignored", self::LOGLEVEL_DEBUG);
                    unset($_containers[$name]);
                    continue;
                }
            }
            if (isset($_containers[$name])) {
                $sortedContainers[] = $_containers[$name];
                unset($_containers[$name]);
            }
        }
        if ($reverse) {
            $sortedContainers = array_reverse($sortedContainers);
        }
        return array_merge($sortedContainers, $_containers);
    }


    /** Volumes and tar exclude options for a container, or null if its backup is skipped */
    public static function backupPlan($container) {
        global $abSettings;

        self::backupLog("Backup {$container['Name']} - Container Volumeinfo: " . print_r($container['Volumes'], true), self::LOGLEVEL_DEBUG);

        $volumes = self::getContainerVolumes($container);

        $containerSettings = $abSettings->getContainerSpecificSettings($container['Name']);

        if ($containerSettings['skipBackup'] == 'yes') {
            self::backupLog("Should NOT backup this container at all. Only include it in stop/start. Skipping backup...");
            return null;
        }

        if ($containerSettings['backupExtVolumes'] == 'no') {
            self::backupLog("Should NOT backup external volumes, sanitizing them...");
            foreach ($volumes as $index => $volume) {
                if (!self::isVolumeWithinAppdata($volume)) {
                    unset($volumes[$index]);
                }
            }
        } else {
            self::backupLog("Backing up EXTERNAL volumes, because it's enabled!");
        }

        $tarExcludes = [
            '--exclude ' . escapeshellarg('/usr/local/share/docker/tailscale_container_hook'),
            '--exclude ' . escapeshellarg('.' . ABSnapshot::PREFIX . '*'), // a leftover btrfs snapshot can sit inside a volume, see ABSnapshot::btrfsFolder()
        ];
        if (!empty($containerSettings['exclude'])) {
            self::backupLog("Container got excludes! " . implode(", ", $containerSettings['exclude']), self::LOGLEVEL_DEBUG);
            foreach ($containerSettings['exclude'] as $exclude) {
                $exclude = rtrim($exclude, "/");
                if (!empty($exclude)) {
                    if (($volumeKey = array_search($exclude, $volumes)) !== false) {
                        self::backupLog("Exclusion \"$exclude\" matches a container volume - ignoring volume/exclusion pair");
                        unset($volumes[$volumeKey]);
                        continue;
                    }
                    // tar compares the text, so /mnt/user/... never matches a volume mapped as /mnt/cache/... (and the reverse)
                    if (str_starts_with($exclude, '/') && strpbrk($exclude, '*?[') === false && !array_filter($volumes, fn($volume) => str_starts_with($exclude, rtrim($volume, '/') . '/'))) {
                        self::backupLog("Exclusion \"$exclude\" is outside every volume of this container, so it excludes nothing. Its volumes: " . implode(', ', $volumes), self::LOGLEVEL_WARN);
                    }
                    $tarExcludes[] = '--exclude ' . escapeshellarg($exclude);
                }
            }
        }

        if (!empty($abSettings->globalExclusions)) {
            self::backupLog("Got global excludes! " . PHP_EOL . print_r($abSettings->globalExclusions, true), self::LOGLEVEL_DEBUG);
            foreach ($abSettings->globalExclusions as $globalExclusion) {
                $tarExcludes[] = '--exclude ' . escapeshellarg($globalExclusion);
            }
        }

        return ['volumes' => array_values($volumes), 'tarExcludes' => $tarExcludes];
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

        self::backupLog("Calculated volumes to back up: " . implode(", ", $volumes));

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
        self::backupLog("Backing up " . $container['Name'] . '...');

        $tarBackupTimer = time();

        $output = $resultcode = null;
        exec(ABSnapshot::command("tar " . $finalTarOptions, $volumes) . " 2>&1 " . ABSettings::$externalCmdPidCapture, $output, $resultcode);
        self::backupLog("Tar out: " . implode('; ', $output), self::LOGLEVEL_DEBUG);

        if (ABSnapshot::setupFailed($resultcode, $output)) {
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
                self::backupLog("lsof($volume)" . PHP_EOL . print_r($output, true), self::LOGLEVEL_DEBUG);
            }

            return $containerSettings['ignoreBackupErrors'] == 'yes';
        }

        self::backupLog("Backup created without issues (took " . gmdate("H:i:s", time() - $tarBackupTimer) . " (hours:mins:secs))");

        if (self::abortRequested()) {
            return true;
        }

        if ($containerSettings['verifyBackup'] == 'yes') {
            $tarVerifyTimer = time();
            self::backupLog("Verifying backup...");
            self::backupLog("Final verify command: " . $finalTarVerifyOptions, self::LOGLEVEL_DEBUG);

            $output = $resultcode = null;
            exec(ABSnapshot::command("tar " . $finalTarVerifyOptions, $volumes) . " 2>&1 " . ABSettings::$externalCmdPidCapture, $output, $resultcode);
            self::backupLog("Tar out: " . implode('; ', $output), self::LOGLEVEL_DEBUG);

            if (ABSnapshot::setupFailed($resultcode, $output)) {
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
                    self::backupLog("lsof($volume)" . PHP_EOL . print_r($output, true), self::LOGLEVEL_DEBUG);
                }

                $nowRunning = $dockerClient->getDockerContainers();
                foreach ($nowRunning as $nowRunningContainer) {
                    if ($nowRunningContainer["Name"] == $container['Name']) {
                        self::backupLog("AFTER verify: " . print_r($nowRunningContainer, true), self::LOGLEVEL_DEBUG);
                    }
                }
                return $containerSettings['ignoreBackupErrors'] == 'yes';
            } else {
                self::backupLog("Verification ended without issues (took " . gmdate("H:i:s", time() - $tarVerifyTimer) . " (hours:mins:secs))");
            }
        } else {
            self::backupLog("Skipping verification for this container because it's not wanted!", self::LOGLEVEL_WARN);
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
        $scriptSaid = $errFile ? trim((string)@file_get_contents($errFile)) : '';
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
        exec('unzip -tq ' . escapeshellarg($target) . ' 2>&1', $output, $resultcode);
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
    public static function getContainerVolumes($container, $skipExclusionCheck = false) {
        global $abSettings;

        $volumes = [];
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
                    continue;
                }

                if (in_array($hostPath, $abSettings->globalExclusions)) {
                    self::backupLog("Ignoring '$hostPath' because it's listed in the global exclusions list!", self::LOGLEVEL_DEBUG);
                    continue;
                }
            }

            // @todo: if no / inside path, we are dealing with a docker volume and not a bind-mount!

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

        $volumes = array_unique($volumes); // Remove duplicate Array values => https://forums.unraid.net/topic/137710-plugin-appdatabackup/?do=findComment&comment=1256267

        usort($volumes, function ($a, $b) {
            return strlen($a) <=> strlen($b);
        });
        self::backupLog("sorted volumes: " . print_r($volumes, true), self::LOGLEVEL_DEBUG);

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
            if (str_starts_with($volume, $appdataPath . '/')) { // Add trailing slash to get exact match! Assures whole dir name!
                self::backupLog("Volume '$volume' IS within AppdataPath '$appdataPath'!", self::LOGLEVEL_DEBUG);
                return true;
            }
        }
        return false;
    }

    public static function errorHandler(int $errno, string $errstr, string $errfile, int $errline, array $errcontext = []): bool {
        $errStr = "got PHP error: $errno / $errstr $errfile:$errline with context: " . json_encode($errcontext);
        file_put_contents("/tmp/appdata.backup_phperr", $errStr . PHP_EOL, FILE_APPEND);
        self::backupLog("PHP-ERROR occurred! $errno / $errstr $errfile:$errline", self::LOGLEVEL_DEBUG);

        return true;
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
        global $abSettings, $dockerContainers, $sortedStopContainers, $sortedStartContainers, $abDestination, $dockerUpdateList;

        self::backupLog(__METHOD__ . ': $containerListOverride: ' . implode(', ', array_column(($containerListOverride ?? []), 'Name')), self::LOGLEVEL_DEBUG);

        switch ($method) {
            case 'stopAll':

                $backupOrder = $containerListOverride ? array_reverse($containerListOverride) : $sortedStopContainers;
                $startOrder  = $containerListOverride ?: $sortedStartContainers;
                $plans       = [];
                $started     = false;
                $skipped     = [];

                self::backupLog("Method: Stop all containers before continuing.");
                foreach ($backupOrder as $_container) {
                    $resolvedContainer = self::resolveContainer($_container, true);
                    foreach (($resolvedContainer !== false ? $resolvedContainer : [$_container]) as $container) {
                        self::setCurrentContainerName($container);
                        $preContainerRet = ABHelper::handlePrePostScript($abSettings->preContainerBackupScript, 'pre-container', $container['Name']);
                        if ($preContainerRet === 2) {
                            self::backupLog("preContainer script decided to skip backup.");
                            $skipped[]                   = $container['Name'];
                            self::$skipStartContainers[] = $container['Name']; // never stopped, so not started either
                            self::setCurrentContainerName($container, true);
                            continue;
                        }
                        if (!self::stopContainer($container)) {
                            $skipped[]                   = $container['Name'];
                            self::$skipStartContainers[] = $container['Name']; // still running
                            self::setCurrentContainerName($container, true);
                            continue;
                        }
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
                        if (!self::startContainers($startOrder)) {
                            return false;
                        }
                        $started = true;
                    } elseif ($volumes) {
                        self::backupLog("Snapshots are not possible for this run - backing up with the containers stopped.", self::LOGLEVEL_WARN);
                    }
                }

                self::backupLog("Starting backup for containers");
                foreach ($backupOrder as $_container) {
                    $resolvedContainer = self::resolveContainer($_container, true);
                    foreach (($resolvedContainer !== false ? $resolvedContainer : [$_container]) as $container) {
                        self::setCurrentContainerName($container);
                        if (in_array($container['Name'], $skipped)) {
                            self::setCurrentContainerName($container, true);
                            continue;
                        }

                        if (!self::backupContainer($container, $abDestination, array_key_exists($container['Name'], $plans) ? $plans[$container['Name']] : false)) {
                            self::$errorOccured = true;
                        }

                        ABHelper::handlePrePostScript($abSettings->postContainerBackupScript, 'post-container', $container['Name']);

                        if (self::abortRequested()) {
                            return false;
                        }

                        if (in_array($container['Name'], $dockerUpdateList)) {
                            self::updateContainer($container['Name']);
                        }
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

                if (!$started && !self::startContainers($startOrder)) {
                    return false;
                }

                break;
            case 'oneAfterTheOther':
                self::backupLog("Method: Stop/Backup/Start");

                if (self::abortRequested()) {
                    return false;
                }

                foreach ($containerListOverride ?: $sortedStopContainers as $container) {

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

                    if (!self::backupContainer($container, $abDestination, $plan)) {
                        self::$errorOccured = true;
                    }

                    ABHelper::handlePrePostScript($abSettings->postContainerBackupScript, 'post-container', $container['Name']);
                    ABSnapshot::destroyAll();

                    if (self::abortRequested()) {
                        return false;
                    }

                    if (in_array($container['Name'], $dockerUpdateList)) {
                        self::updateContainer($container['Name']);
                    }

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
