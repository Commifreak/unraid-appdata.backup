<?php

namespace unraid\plugins\AppdataBackup;

require_once __DIR__ . '/ABHelper.php';

/**
 * This class offers a convenient way to retrieve settings
 */
class ABSettings {

    public static $appName = 'appdata.backup';
    public static $pluginDir = '/boot/config/plugins/appdata.backup';
    public static $settingsFile = 'config.json';
    public static $unraidAutostartFile = "/var/lib/docker/unraid-autostart";
    public static $settingsVersion = 3;
    public static $cronFile = 'appdata_backup.cron';
    public static $supportUrl = 'https://forums.unraid.net/topic/137710-plugin-appdatabackup/';

    public static $tempFolder = '/tmp/appdata.backup';

    public static $logfile = 'ab.log';
    public static $debugLogFile = 'ab.debug.log';

    public static $stateFileScriptRunning = 'running';
    public static $stateFileAbort = 'abort';
    public static $stateExtCmd = 'extCmd';

    public static $emhttpVars = '/var/local/emhttp/var.ini';

    public static $qemuFolder = '/etc/libvirt/qemu';
    public static $externalCmdPidCapture = '';


    public string|null $backupMethod = 'oneAfterTheOther';
    public string|int $deleteBackupsOlderThan = '7';
    public string|int $keepMinBackups = '3';

    /**
     * @var array|string[] Allowed sources - WITHOUT trailing slash!
     */
    public array $allowedSources = ['/mnt/user/appdata', '/mnt/cache/appdata'];
    public string $destination = '';
    public string $compression = 'yes';
    public string|int $compressionCpuLimit = '0';
    public array $defaults = [
        'verifyBackup'       => 'yes',
        'ignoreBackupErrors' => 'no',
        'updateContainer'    => 'no',
        'skipBackup' => 'no',
        'group' => '',

        // The following are hidden, container special default settings
        'skip'               => 'no',
        'exclude' => [],
        'dontStop'           => 'no',
        'backupExtVolumes'   => 'no'
    ];
    public string $flashBackup = 'yes';
    public string $flashBackupCopy = '';
    public string $notification = ABHelper::LOGLEVEL_ERR;
    public string $backupFrequency = 'disabled';
    public string|int $backupFrequencyWeekday = '1';
    public string|int $backupFrequencyDayOfMonth = '1';
    public string|int $backupFrequencyHour = '0';
    public string|int $backupFrequencyMinute = '0';
    public string $backupFrequencyCustom = '';
    public array $containerSettings = [];
    public array $containerOrder = [];
    public array $containerGroupOrder = [];
    public string $preRunScript = '';
    public string $preBackupScript = '';
    public string $postBackupScript = '';
    public string $postRunScript = '';
    public string $preContainerBackupScript = '';
    public string $postContainerBackupScript = '';
    public array $includeFiles = [];
    public array $globalExclusions = [];
    public string $backupVMMeta = 'yes';
    public string $successLogWanted = 'no';
    public string $updateLogWanted = 'no';
    public string $ignoreExclusionCase = 'no';
    public array $volumePathMappings = [];

    public function __construct() {

        self::migrateConfig();

        $sFile = self::getConfigPath();
        if (file_exists($sFile)) {
            $config = json_decode(file_get_contents($sFile), true);
            if ($config) {
                foreach ($config as $key => $value) {
                    if (property_exists($this, $key)) {
                        switch ($key) {
                            case 'defaults':
                                $this->$key = array_merge($this->defaults, $value);
                                break;
                            case 'allowedSources':
                            case 'includeFiles':
                            case 'globalExclusions':
                                $paths    = explode("\r\n", $value);
                                $newPaths = [];
                                foreach ($paths as $pathKey => $path) {
                                    if (empty(trim($path))) {
                                        continue; // Skip empty lines
                                    }
                                    $newPaths[] = rtrim($path, '/');
                                }
                                $this->$key = $newPaths;
                                break;
                            case 'volumePathMappings':
                                $mappings = [];
                                $lines = explode("\r\n", $value);
                                foreach ($lines as $line) {
                                    $line = trim($line);
                                    if (empty($line) || str_starts_with($line, '#')) {
                                        continue; // Skip empty lines and comments
                                    }
                                    // Parse mapping in format: /original/path => /mapped/path
                                    if (preg_match('/^(.+?)\s*=>\s*(.+)$/', $line, $matches)) {
                                        $from = rtrim($matches[1], '/');
                                        $to = rtrim($matches[2], '/');
                                        $mappings[] = ['from' => $from, 'to' => $to];
                                    }
                                }
                                $this->$key = $mappings;
                                break;
                            case 'containerOrder':
                                // HACK - if something goes wrong while we transfer the jQuery sortable data, the value here would NOT be an array. Better safe than sorry: Force to empty array if it isnt one.
                                $this->$key = is_array($value) ? $value : [];
                                break;
                            case 'settingsVersion':
                                $this::$settingsVersion = $value;
                                break;
                            case 'containerSettings':
                                /**
                                 * Container specific patches
                                 */
                                foreach ($value as $containerName => $containerSettings) {
                                    $paths    = explode("\r\n", $containerSettings['exclude']);
                                    $newPaths = [];
                                    foreach ($paths as $pathKey => $path) {
                                        if (empty(trim($path))) {
                                            continue; // Skip empty lines
                                        }
                                        $newPaths[] = rtrim($path, '/');
                                    }
                                    $value[$containerName]['exclude'] = $newPaths;
                                }
                                $this->$key = $value;
                                break;
                            default:
                                $this->$key = $value;
                                break;
                        }
                    }
                }
            }
        }
        ABHelper::$targetLogLevel = $this->notification;

        /**
         * Check obsolete containers only if array is online, socket error otherwise!
         */
        if (ABHelper::isArrayOnline()) {

            require_once("/usr/local/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php");

            // Get containers and check if some of it is deleted but configured
            $dockerClient = new \DockerClient();
            foreach ($this->containerSettings as $name => $settings) {
                if (!$dockerClient->doesContainerExist($name)) {
                    unset($this->containerSettings[$name]);
                    $sortKey = array_search($name, $this->containerOrder);
                    if ($sortKey !== false) {
                        unset($this->containerOrder[$sortKey]);
                    }
                }
            }
        }
    }

    public static function getConfigPath() {
        return self::$pluginDir . DIRECTORY_SEPARATOR . self::$settingsFile;
    }

    /**
     * Execute config migration, if necessary
     * @return void
     */
    public static function migrateConfig() {
        $sFile = self::getConfigPath();
        if (file_exists($sFile)) {
            $config = json_decode(file_get_contents($sFile), true);
            if ($config) {
                if (!isset($config['settingsVersion'])) {
                    $config['settingsVersion'] = 1; // No version set, set the current one
                }
                for ($curMigrationStep = $config['settingsVersion']; $curMigrationStep < ABSettings::$settingsVersion; $curMigrationStep++) {
                    exec('logger -t "' . self::$appName . '" Found migrations! Running Migration for version ' . $curMigrationStep . ' to ' . (ABSettings::$settingsVersion));

                    $migrationSuccess = false;
                    switch ($curMigrationStep) {
                        case 1:
                            /**
                             * Correct notification setting errors to error
                             */
                            if ($config['notification'] == 'errors') {
                                $config['notification'] = ABHelper::LOGLEVEL_ERR;
                            }
                            $migrationSuccess = true;
                            break;
                        case 2:
                            /**
                             * New adjustable default option: dontStop. Set any per-container "no" to "" (empty)
                             */
                            foreach ($config['containerSettings'] ?? [] as $name => $containerSettings) {
                                if (isset($containerSettings['dontStop']) && $containerSettings['dontStop'] == 'no') {
                                    $config['containerSettings'][$name]['dontStop'] = '';
                                }
                            }
                            $migrationSuccess = true;
                            break;
                    }
                    if ($migrationSuccess) {
                        exec('logger -t "' . self::$appName . '" Migration was successful!');
                        $config['settingsVersion']++;
                        self::store($config);
                    } else {
                        exec('logger -t "' . self::$appName . '" ERROR: Migration was NOT successful!');
                    }
                }
            }
        }
    }

    /**
     * Stores a config to disk
     * @param array $config
     * @return void
     */
    public static function store(array $config) {
        file_put_contents(ABSettings::getConfigPath(), json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Calculates container specific settings
     * @param $name string container name
     * @param bool $setEmptyToDefault set empty settings to their default state (true) or leave it empty (for settings page)
     * @return array
     */
    public function getContainerSpecificSettings($name, $setEmptyToDefault = true) {
        if (!isset($this->containerSettings[$name])) {
            /**
             * Container is unknown, init its values with empty strings = 'use default'
             */
            foreach ($this->defaults as $setting => $value) {
                $this->containerSettings[$name][$setting] = is_array($value) ? [] : '';
            }
        }

        $settings = array_merge($this->defaults, $this->containerSettings[$name]);

        if ($setEmptyToDefault) {
            foreach ($settings as $setting => $value) {
                if (empty($value) && isset($this->defaults[$setting])) {
                    $settings[$setting] = $this->defaults[$setting];
                }
            }
        }

        $settings['group'] = preg_replace('/[\W]/', '', $settings['group']);

        return $settings;
    }

    /**
     * Returns all container groups
     * @param $filter false|string false: whole list, string: container name to return
     * @return array|mixed
     */
    public function getContainerGroups($filter = false) {
        $groups = [];
        foreach ($this->containerSettings as $container => $setting) {
            $containersettings = $this->getContainerSpecificSettings($container);
            $group             = $containersettings['group'];
            if (!empty($group)) {
                $groups[$group][] = $container;
            }
        }
        if (!empty($filter) && isset($groups[$filter])) {
            return $groups[$filter];
        }
        return $groups;
    }

    /**
     * @return array
     */
    public function checkCron() {
        $cronSettings = '# Appdata.Backup cron settings' . PHP_EOL;
        switch ($this->backupFrequency) {
            case 'custom':
                $cronSettings .= $this->backupFrequencyCustom;
                break;
            case 'daily':
                $cronSettings .= $this->backupFrequencyMinute . " " . $this->backupFrequencyHour . " * * *";
                break;
            case 'weekly':
                $cronSettings .= $this->backupFrequencyMinute . " " . $this->backupFrequencyHour . " * * " . $this->backupFrequencyWeekday;
                break;
            case 'monthly':
                $cronSettings .= $this->backupFrequencyMinute . " " . $this->backupFrequencyHour . " " . $this->backupFrequencyDayOfMonth . " * *";
                break;
            default:
                $cronSettings = '';
        }

        if (!empty($cronSettings)) {
            $cronSettings .= ' php ' . dirname(__DIR__) . '/scripts/backup.php > /dev/null 2>&1';
            file_put_contents(ABSettings::$pluginDir . '/' . ABSettings::$cronFile, $cronSettings . PHP_EOL);
        } elseif (file_exists(ABSettings::$pluginDir . '/' . ABSettings::$cronFile)) {
            unlink(ABSettings::$pluginDir . '/' . ABSettings::$cronFile);
        }
        // Let dcron know our changes via update_cron
        $out = $code = 0;
        exec("update_cron", $out, $code);
        return [$code, $out];
    }

}

// Init some default values
if (str_contains(__DIR__, 'appdata.backup.beta')) {
    ABSettings::$appName    .= '.beta';
    ABSettings::$pluginDir  .= '.beta';
    ABSettings::$tempFolder .= '.beta';
    ABSettings::$supportUrl = 'https://forums.unraid.net/topic/136995-pluginbeta-appdatabackup/';
}
ABSettings::$externalCmdPidCapture = '& echo $! > ' . escapeshellarg(ABSettings::$tempFolder . '/' . ABSettings::$stateExtCmd) . ' && wait $!';

if (!file_exists(ABSettings::$tempFolder)) {
    mkdir(ABSettings::$tempFolder);
}
