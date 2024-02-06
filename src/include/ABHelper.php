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
     * @return bool
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

            if ($resultcode != 0) {
                self::backupLog("Script did not returned 0 (it returned $resultcode)!", self::LOGLEVEL_WARN);
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
        $command = '/usr/local/emhttp/webGui/scripts/notify -e "Appdata Backup" -s "' . $subject . '" -d "' . $description . '" -m "' . $message . '" -i "' . $type . '" -l "/Settings/AB.Main"';
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
            self::backupLog("Stopping " . $container['Name'] . "... ", self::LOGLEVEL_INFO, false);
            $stopTimer      = time();
            $dockerStopCode = $dockerClient->stopContainer($container['Name']);
            if ($dockerStopCode != 1) {
                self::backupLog("Error while stopping container! Code: " . $dockerStopCode . " - trying 'docker stop' method", self::LOGLEVEL_WARN, true, true);
                $out = $code = null;
                exec("docker stop " . escapeshellarg($container['Name']) . " -t 30", $out, $code);
                if ($code == 0) {
                    self::backupLog("That _seemed_ to work.");
                } else {
                    self::backupLog("docker stop variant was unsuccessful as well! Docker said: " . implode(', ', $out), self::LOGLEVEL_ERR);
                }
            } else {
                self::backupLog("done! (took " . (time() - $stopTimer) . " seconds)", self::LOGLEVEL_INFO, true, true);
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

                self::backupLog("Container did not started! - Code: " . $dockerStartCode, self::LOGLEVEL_WARN, true, true);
                if ($dockerStartTry < 3) {
                    $dockerStartTry++;
                    sleep(5);
                } else {
                    self::backupLog("Container did not started after multiple tries, skipping.", self::LOGLEVEL_ERR);
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


    /**
     * The heart func: take care of creating a backup!
     * @param $container array
     * @param $destination string The generated backup folder for this backup run
     * @return bool
     */
    public static function backupContainer($container, $destination) {
        global $abSettings, $dockerClient;

        self::backupLog("Backup {$container['Name']} - Container Volumeinfo: " . print_r($container['Volumes'], true), self::LOGLEVEL_DEBUG);

        $volumes = self::getContainerVolumes($container);

        $containerSettings = $abSettings->getContainerSpecificSettings($container['Name']);

        if ($containerSettings['skipBackup'] == 'yes') {
            self::backupLog("Should NOT backup this container at all. Only include it in stop/start. Skipping backup...");
            return true;
        }

        if ($containerSettings['backupExtVolumes'] == 'no') {
            self::backupLog("Should NOT backup external volumes, sanitizing them...");
            foreach ($volumes as $index => $volume) {
                if (!self::isVolumeWithinAppdata($volume)) {
                    unset($volumes[$index]);
                }
            }
        } else {
            self::backupLog("Backing up EXTERNAL volumes, because its enabled!");
        }

        $tarExcludes = [];
        if (!empty($containerSettings['exclude'])) {
            self::backupLog("Container got excludes! " . PHP_EOL . print_r($containerSettings['exclude'], true), self::LOGLEVEL_DEBUG);
            $excludes = explode("\r\n", $containerSettings['exclude']);
            if (!empty($excludes)) {
                foreach ($excludes as $exclude) {
                    $exclude = rtrim($exclude, "/");
                    if (!empty($exclude)) {
                        if (($volumeKey = array_search($exclude, $volumes)) !== false) {
                            self::backupLog("Exclusion \"$exclude\" matches a container volume - ignoring volume/exclusion pair");
                            unset($volumes[$volumeKey]);
                            continue;
                        }
                        $tarExcludes[] = '--exclude ' . escapeshellarg($exclude);
                    }
                }
            }
        }

        if (!empty($abSettings->globalExclusions)) {
            self::backupLog("Got global excludes! " . PHP_EOL . print_r($abSettings->globalExclusions, true), self::LOGLEVEL_DEBUG);
            foreach ($abSettings->globalExclusions as $globalExclusion) {
                $tarExcludes[] = '--exclude ' . escapeshellarg($globalExclusion);
            }
        }

        if (empty($volumes)) {
            self::backupLog($container['Name'] . " does not have any volume to back up! Skipping. Please consider ignoring this container.", self::LOGLEVEL_WARN);
            return true;
        }

        self::backupLog("Calculated volumes to back up: " . implode(", ", $volumes));

        $destination = $destination . "/" . $container['Name'] . '.tar';

        $tarVerifyOptions = array_merge($tarExcludes, ['--diff']);      // Add excludes to the beginning - https://unix.stackexchange.com/a/33334
        $tarOptions       = array_merge($tarExcludes, ['-c', '-P']);    // Add excludes to the beginning - https://unix.stackexchange.com/a/33334

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
            self::backupLog("tar creation failed! Tar said: " . implode('; ', $output), $containerSettings['ignoreBackupErrors'] == 'yes' ? self::LOGLEVEL_INFO : self::LOGLEVEL_ERR);
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
        global $abSettings;

        $volumes = [];
        foreach ($container['Volumes'] ?? [] as $volume) {
            $hostPath = rtrim(explode(":", $volume)[0], '/');
            if (empty($hostPath)) {
                self::backupLog("This volume is empty (rootfs mapped??)! Ignoring.", self::LOGLEVEL_DEBUG);
                continue;
            }
            if (!file_exists($hostPath)) {
                self::backupLog("'$hostPath' does NOT exist! Please check your mappings! Skipping it for now.", self::LOGLEVEL_ERR);
                continue;
            }
            if (in_array($hostPath, $abSettings->allowedSources)) {
                self::backupLog("Removing container mapping \"$hostPath\" because it is a source path!");
                continue;
            }
            $volumes[] = $hostPath;
        }

        $volumes = array_unique($volumes); // Remove duplicate Array values => https://forums.unraid.net/topic/137710-plugin-appdatabackup/?do=findComment&comment=1256267

        usort($volumes, function ($a, $b) {
            return strlen($a) <=> strlen($b);
        });
        self::backupLog("usorted volumes: " . print_r($volumes, true), self::LOGLEVEL_DEBUG);

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

    public static function errorHandler(int $errno, string $errstr, string $errfile, int $errline): bool {
        self::notify("Appdata Backup PHP error", "Appdata Backup PHP error", "got PHP error: $errno / $errstr $errfile:$errline", 'alert');
        self::backupLog("got PHP error: $errno / $errstr $errfile:$errline", self::LOGLEVEL_ERR);

        return true;
    }

    public static function updateContainer($name) {
        global $abSettings;
        ABHelper::backupLog("Installing planned update for $name...");
        exec('/usr/local/emhttp/plugins/dynamix.docker.manager/scripts/update_container ' . escapeshellarg($name));

        if ($abSettings->updateLogWanted == 'yes') {
            ABHelper::notify("Appdata Backup", "Container '$name' updated!", "Container '$name' was successfully updated during this backup run!");
        }
    }

    public static function doBackupMethod($method, $containerListOverride = null,) {
        global $abSettings, $dockerContainers, $sortedStopContainers, $sortedStartContainers, $abDestination, $dockerUpdateList;

        switch ($method) {
            case 'stopAll':

                ABHelper::backupLog("Method: Stop all container before continuing.");
                foreach ($containerListOverride ?: $sortedStopContainers as $_container) {
                    foreach ((self::resolveContainer($_container) ?: [$_container]) as $container) {
                        ABHelper::setCurrentContainerName($container);
                        ABHelper::stopContainer($container);

                        if (ABHelper::abortRequested()) {
                            return false;
                        }
                    }
                    ABHelper::setCurrentContainerName($_container, true);
                }

                if (ABHelper::abortRequested()) {
                    return false;
                }

                ABHelper::backupLog("Starting backup for containers");
                foreach ($containerListOverride ?: $sortedStopContainers as $_container) {
                    foreach (self::resolveContainer($_container) ?: [$_container] as $container) {
                        ABHelper::setCurrentContainerName($container);
                        if (!ABHelper::backupContainer($container, $abDestination)) {
                            ABHelper::$errorOccured = true;
                        }

                        if (ABHelper::abortRequested()) {
                            return false;
                        }

                        if (in_array($container['Name'], $dockerUpdateList)) {
                            ABHelper::updateContainer($container['Name']);
                        }
                    }
                    ABHelper::setCurrentContainerName($_container, true);
                }

                if (ABHelper::abortRequested()) {
                    return false;
                }

                ABHelper::handlePrePostScript($abSettings->postBackupScript, 'post-backup', $abDestination);

                if (ABHelper::abortRequested()) {
                    return false;
                }

                ABHelper::backupLog("Set containers to previous state");
                foreach ($containerListOverride ? array_reverse($containerListOverride) : $sortedStartContainers as $_container) {
                    foreach (self::resolveContainer($_container, true) ?: [$_container] as $container) {
                        ABHelper::setCurrentContainerName($container);
                        ABHelper::startContainer($container);

                        if (ABHelper::abortRequested()) {
                            return false;
                        }
                    }
                    ABHelper::setCurrentContainerName($_container, true);
                }

                break;
            case 'oneAfterTheOther':
                ABHelper::backupLog("Method: Stop/Backup/Start");

                if (ABHelper::abortRequested()) {
                    return false;
                }

                foreach ($containerListOverride ?: $sortedStopContainers as $container) {

                    if ($container['isGroup']) {
                        self::doBackupMethod('stopAll', self::resolveContainer($container));
                        ABHelper::setCurrentContainerName($container, true);
                        continue;
                    }

                    ABHelper::setCurrentContainerName($container);
                    ABHelper::stopContainer($container);

                    if (ABHelper::abortRequested()) {
                        return false;
                    }

                    if (!ABHelper::backupContainer($container, $abDestination)) {
                        ABHelper::$errorOccured = true;
                    }

                    if (ABHelper::abortRequested()) {
                        return false;
                    }

                    if (in_array($container['Name'], $dockerUpdateList)) {
                        ABHelper::updateContainer($container['Name']);
                    }

                    if (ABHelper::abortRequested()) {
                        return false;
                    }

                    ABHelper::startContainer($container);

                    if (ABHelper::abortRequested()) {
                        return false;
                    }
                    ABHelper::setCurrentContainerName($container, true);
                }
                ABHelper::handlePrePostScript($abSettings->postBackupScript, 'post-backup', $abDestination);

                break;
        }
        return true;
    }

    public static function resolveContainer($container, $reverse = false) {
        global $dockerContainers, $abSettings;
        if ($container['isGroup']) {
            ABHelper::setCurrentContainerName($container);
            $groupMembers = $abSettings->getContainerGroups($container['Name']);
            ABHelper::backupLog("Reached a group: " . $container['Name'], self::LOGLEVEL_DEBUG);
            $sortedGroupContainers = ABHelper::sortContainers($dockerContainers, $abSettings->containerGroupOrder[$container['Name']], $reverse, false, $groupMembers);
            ABHelper::backupLog("Containers in this group:", self::LOGLEVEL_DEBUG);
            ABHelper::backupLog(print_r($sortedGroupContainers, true), self::LOGLEVEL_DEBUG);
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
