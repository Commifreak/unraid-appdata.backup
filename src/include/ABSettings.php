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

    /** The settings the Extra schedule tab saves; the Settings tab's form keeps them (see storeForm) */
    const EXTRA_FIELDS = ['extraFrequency', 'extraFrequencyWeekday', 'extraFrequencyDayOfMonth', 'extraFrequencyHour', 'extraFrequencyMinute', 'extraFrequencyCustom', 'extraContainers', 'extraDestination', 'extraDeleteBackupsOlderThan', 'extraKeepMinBackups'];

    public static $tempFolder = '/tmp/appdata.backup';

    public static $logfile = 'ab.log';
    public static $debugLogFile = 'ab.debug.log';

    public static $stateFileScriptRunning = 'running';
    public static $stateFileLock = 'lock'; // never deleted: see ABHelper::claimRun()
    public static $stateFileAbort = 'abort';
    public static $stateExtCmd = 'extCmd';
    public static $stateFileStep = 'step';

    public static $emhttpVars = '/var/local/emhttp/var.ini';

    public static $qemuFolder = '/etc/libvirt/qemu';
    public static $externalCmdPidCapture = '';


    public string|null $backupMethod = 'oneAfterTheOther';
    public string $snapshotMode = 'no';
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
    public string $extraSchedule = 'no';
    public string $extraFrequency = 'disabled';
    public string|int $extraFrequencyWeekday = '1';
    public string|int $extraFrequencyDayOfMonth = '1';
    public string|int $extraFrequencyHour = '0';
    public string|int $extraFrequencyMinute = '0';
    public string $extraFrequencyCustom = '';
    public array $extraContainers = [];
    public string $extraDestination = '';
    public string|int $extraDeleteBackupsOlderThan = '7';
    public string|int $extraKeepMinBackups = '3';
    /** '' for the main schedule, 'extra' once forSchedule() made these the extra schedule's settings */
    public string $schedule = '';
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
                                $paths    = preg_split('/\r?\n|\r/', $value);
                                $newPaths = [];
                                foreach ($paths as $pathKey => $path) {
                                    if (empty(trim($path))) {
                                        continue; // Skip empty lines
                                    }
                                    $newPaths[] = rtrim($path, '/');
                                }
                                $this->$key = $newPaths;
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
                                    $paths    = preg_split('/\r?\n|\r/', $containerSettings['exclude']);
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

    /** Stores a posted form: the Settings tab's replaces all but EXTRA_FIELDS, the Extra schedule tab's (extraScheduleForm) only those; false if the saved config cannot be read to keep the rest */
    public static function storeForm(array $post) {
        $raw   = @file_get_contents(self::getConfigPath());
        $saved = $raw === false ? [] : json_decode($raw, true);
        $extra = array_flip(self::EXTRA_FIELDS);
        if (!isset($post['extraScheduleForm'])) {
            if (!isset($post['containerSettings']) && is_array($saved)) {
                // No container panels were drawn (Docker down?): keep the saved ones instead of wiping them
                $post = array_intersect_key($saved, array_flip(['containerSettings', 'containerOrder', 'containerGroupOrder'])) + $post;
            }
            self::store($post + (is_array($saved) ? array_intersect_key($saved, $extra) : []));
            return true;
        }
        if (!is_array($saved)) {
            return false; // storing only the extra fields would wipe every other setting
        }
        // An empty multi-select posts nothing, so no key means no containers
        self::store(array_diff_key($saved, $extra) + array_intersect_key($post, $extra) + ['extraContainers' => []]);
        return true;
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

    /** These settings as $schedule runs them: 'extra' swaps in its frequency, destination and retention, and backs up containers only */
    public function forSchedule($schedule) {
        if ($schedule !== 'extra') {
            return $this;
        }
        $settings           = clone $this;
        $settings->schedule = 'extra';
        foreach (['', 'Weekday', 'DayOfMonth', 'Hour', 'Minute', 'Custom'] as $field) {
            $settings->{'backupFrequency' . $field} = $this->{'extraFrequency' . $field};
        }
        $settings->destination            = $this->extraDestination;
        $settings->deleteBackupsOlderThan = $this->extraDeleteBackupsOlderThan;
        $settings->keepMinBackups         = $this->extraKeepMinBackups;
        $settings->flashBackup            = 'no';
        $settings->backupVMMeta           = 'no';
        $settings->includeFiles           = [];
        return $settings;
    }

    /** The DockerClient containers this schedule backs up: all of them, or the extra schedule's choice, where `__grp__<name>` stands for that group's members */
    public function scheduleContainers($containers) {
        if ($this->schedule !== 'extra') {
            return $containers;
        }
        $groups = $this->getContainerGroups();
        $chosen = [];
        foreach ($this->extraContainers as $name) {
            $chosen = array_merge($chosen, str_starts_with($name, '__grp__') ? ($groups[substr($name, 7)] ?? []) : [$name]);
        }
        return array_values(array_filter($containers ?: [], fn($container) => in_array($container['Name'], $chosen, true)));
    }

    /** What the extra schedule chose that is gone, e.g. renamed: containers DockerClient does not list, and groups without members; [] for the main schedule */
    public function scheduleMissing($containers) {
        if ($this->schedule !== 'extra') {
            return [];
        }
        $groups  = $this->getContainerGroups();
        $present = array_column($containers ?: [], 'Name');
        $missing = [];
        foreach ($this->extraContainers as $name) {
            if (str_starts_with($name, '__grp__')) {
                if (!isset($groups[substr($name, 7)])) {
                    $missing[] = 'group ' . substr($name, 7);
                }
            } elseif (!in_array($name, $present, true)) {
                $missing[] = $name;
            }
        }
        return $missing;
    }

    /** The cron time fields for the schedule whose settings start with $prefix, or '' when it is off */
    private function cronTime($prefix) {
        $minute = $this->{$prefix . 'Minute'};
        $hour   = $this->{$prefix . 'Hour'};
        return match ($this->$prefix) {
            'custom' => trim($this->{$prefix . 'Custom'}),
            'daily' => "$minute $hour * * *",
            'weekly' => "$minute $hour * * " . $this->{$prefix . 'Weekday'},
            'monthly' => "$minute $hour " . $this->{$prefix . 'DayOfMonth'} . " * *",
            default => '',
        };
    }

    /**
     * @return array
     */
    public function checkCron() {
        $lines = [];
        // 'scheduled' makes a run wait for a running job instead of being refused
        foreach (['backupFrequency' => 'scheduled', 'extraFrequency' => 'scheduled extra'] as $prefix => $args) {
            $time = $prefix === 'extraFrequency' && $this->extraSchedule !== 'yes' ? '' : $this->cronTime($prefix);
            if ($time !== '') {
                $lines[] = $time . ' php ' . dirname(__DIR__) . '/scripts/backup.php ' . $args . ' > /dev/null 2>&1';
            }
        }

        if ($lines) {
            file_put_contents(ABSettings::$pluginDir . '/' . ABSettings::$cronFile, '# Appdata.Backup cron settings' . PHP_EOL . implode(PHP_EOL, $lines) . PHP_EOL);
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
