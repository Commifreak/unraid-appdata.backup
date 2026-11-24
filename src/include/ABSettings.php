<?php

namespace unraid\plugins\AppdataBackup;

require_once __DIR__ . '/ABHelper.php';
require_once __DIR__ . '/ABRetention.php';

/**
 * This class offers a convenient way to retrieve settings
 */
class ABSettings {

    public static $appName = 'appdata.backup';
    public static $pluginDir = '/boot/config/plugins/appdata.backup';
    public static $settingsFile = 'config.json';
    public static $unraidAutostartFile = "/var/lib/docker/unraid-autostart";
    public static $settingsVersion = 3;
    /** Docker tab order: written by the Docker tab when containers are dragged, and removed by its reset button */
    public static $unraidUserPrefsFile = "/boot/config/plugins/dockerMan/userprefs.cfg";
    public static $cronFile = 'appdata_backup.cron';
    public static $supportUrl = 'https://forums.unraid.net/topic/137710-plugin-appdatabackup/';

    /** The settings the Extra schedule tab saves; the Settings tab's form keeps them (see storeForm) */
    const EXTRA_FIELDS = ['extraFrequency', 'extraFrequencyWeekday', 'extraFrequencyDayOfMonth', 'extraFrequencyHour', 'extraFrequencyMinute', 'extraFrequencyCustom', 'extraContainers', 'extraContainerOrder', 'extraContainerSettings', 'extraDestination', 'extraRetentionMode', 'extraDeleteBackupsOlderThan', 'extraKeepMinBackups',
        'extraKeepDaily', 'extraKeepWeekly', 'extraKeepMonthly', 'extraKeepYearly', 'extraBackupMethod', 'extraSnapshotMode', 'extraCompression', 'extraCompressionCpuLimit', 'extraFlashBackup', 'extraFlashBackupCopy', 'extraBackupVMMeta', 'extraNotification', 'extraSuccessLogWanted', 'extraUpdateLogWanted',
        'extraScripts', 'extraPreRunScript', 'extraPreBackupScript', 'extraPreContainerBackupScript', 'extraPostContainerBackupScript', 'extraPostBackupScript', 'extraPostRunScript', 'extraIncludeFiles', 'extraGlobalExclusionsOwn', 'extraGlobalExclusions',
        'extraDefaultUpdateContainer', 'extraDefaultDontStop', 'extraDefaultVerifyBackup', 'extraDefaultIgnoreBackupErrors'];

    /** Run-wide settings the Extra schedule tab can set for itself: main => extra, where the extra value '' means the Settings tab's */
    const EXTRA_SAME_AS = ['backupMethod' => 'extraBackupMethod', 'snapshotMode' => 'extraSnapshotMode', 'compression' => 'extraCompression', 'compressionCpuLimit' => 'extraCompressionCpuLimit', 'notification' => 'extraNotification', 'successLogWanted' => 'extraSuccessLogWanted', 'updateLogWanted' => 'extraUpdateLogWanted'];

    /** Retention settings (ABRetention), each with the extra schedule's own 'extra' . ucfirst() twin */
    const RETENTION = ['retentionMode', 'deleteBackupsOlderThan', 'keepMinBackups', 'keepDaily', 'keepWeekly', 'keepMonthly', 'keepYearly'];

    /** Run-wide extras the extra schedule makes only with its own Yes, so a frequent run stays on its containers: main => extra */
    const EXTRA_OWN_YES = ['flashBackup' => 'extraFlashBackup', 'backupVMMeta' => 'extraBackupVMMeta'];

    /** The six script hooks, which the extra schedule replaces together (extraScripts = own; an empty one means no script) */
    const SCRIPTS = ['preRunScript', 'preBackupScript', 'preContainerBackupScript', 'postContainerBackupScript', 'postBackupScript', 'postRunScript'];

    /** The extra schedule's own container defaults: setting => field ('' = the Settings tab's per-container value, then its default) */
    const EXTRA_DEFAULTS = ['updateContainer' => 'extraDefaultUpdateContainer', 'dontStop' => 'extraDefaultDontStop', 'verifyBackup' => 'extraDefaultVerifyBackup', 'ignoreBackupErrors' => 'extraDefaultIgnoreBackupErrors'];

    /** Per-container settings the Extra schedule tab can set for itself ('' = same as the Settings tab) */
    const EXTRA_CONTAINER_KEYS = ['backupExtVolumes', 'updateContainer', 'skipBackup', 'verifyBackup', 'ignoreBackupErrors', 'dontStop'];

    /** Why the last storeForm kept Skip new containers? as saved (settings.php shows it); null when it did not */
    public static ?string $skipKeptWhy = null;

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


    /** The backup type a fresh install gets, and the one a schedule copying a Flash only Settings tab backs its containers up with */
    const DEFAULT_BACKUP_METHOD = 'oneAfterTheOther';

    /** 'stopAll', 'oneAfterTheOther', or 'flashOnly' (no container is stopped or backed up, see flashOnly()) */
    public string|null $backupMethod = self::DEFAULT_BACKUP_METHOD;
    public string $snapshotMode = 'no';
    /** 'age' (deleteBackupsOlderThan, keepMinBackups) or 'gfs' (ABRetention::PERIODS) */
    public string $retentionMode = 'age';
    public string|int $deleteBackupsOlderThan = '7';
    public string|int $keepMinBackups = '3';
    public string|int $keepDaily = '7';
    public string|int $keepWeekly = '4';
    public string|int $keepMonthly = '12';
    public string|int $keepYearly = '3';

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
        'skip'               => 'no', // Skip new containers? on the Settings tab

        // The following are hidden, container special default settings
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
    public array $extraContainerOrder = [];
    /** Per container, like containerSettings; '' = same as the Settings tab, exclude only counts with excludeOwn = yes */
    public array $extraContainerSettings = [];
    public string $extraBackupMethod = '';
    public string $extraSnapshotMode = '';
    public string $extraCompression = '';
    public string|int $extraCompressionCpuLimit = '';
    public string $extraFlashBackup = 'no';
    public string $extraFlashBackupCopy = '';
    public string $extraBackupVMMeta = 'no';
    public string $extraNotification = '';
    public string $extraSuccessLogWanted = '';
    public string $extraUpdateLogWanted = '';
    public string $extraScripts = '';
    public string $extraPreRunScript = '';
    public string $extraPreBackupScript = '';
    public string $extraPreContainerBackupScript = '';
    public string $extraPostContainerBackupScript = '';
    public string $extraPostBackupScript = '';
    public string $extraPostRunScript = '';
    public array $extraIncludeFiles = [];
    public string $extraGlobalExclusionsOwn = '';
    public array $extraGlobalExclusions = [];
    public string $extraDefaultUpdateContainer = '';
    public string $extraDefaultDontStop = '';
    public string $extraDefaultVerifyBackup = '';
    public string $extraDefaultIgnoreBackupErrors = '';
    public string $extraDestination = '';
    public string $extraRetentionMode = 'age';
    public string|int $extraDeleteBackupsOlderThan = '7';
    public string|int $extraKeepMinBackups = '3';
    public string|int $extraKeepDaily = '7';
    public string|int $extraKeepWeekly = '4';
    public string|int $extraKeepMonthly = '12';
    public string|int $extraKeepYearly = '3';
    /** '' for the main schedule, 'extra' once forSchedule() made these the extra schedule's settings */
    public string $schedule = '';
    public array $containerSettings = [];
    /** 'custom' (also any value but 'unraid') starts containers in the saved containerOrder list; 'unraid' in the Docker tab's order */
    public string $containerOrderMode = 'custom';
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
                        if (in_array(str_starts_with($key, 'extra') ? lcfirst(substr($key, 5)) : $key, self::RETENTION, true) && !is_string($value) && !is_int($value)) {
                            $value = json_encode($value); // a count PHP would cast to 1 (1.5, true) stays text that ABRetention::count() rejects, so retention is off
                        }
                        switch ($key) {
                            case 'defaults':
                                $this->$key = array_merge($this->defaults, $value);
                                break;
                            case 'allowedSources':
                            case 'includeFiles':
                            case 'globalExclusions':
                            case 'extraIncludeFiles':
                            case 'extraGlobalExclusions':
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
                            case 'containerOrderMode':
                                $this->$key = $value === 'unraid' ? 'unraid' : 'custom';
                                break;
                            case 'retentionMode':
                            case 'extraRetentionMode':
                                $this->$key = $value === 'gfs' ? 'gfs' : 'age';
                                break;
                            case 'containerOrder':
                                // HACK - if something goes wrong while we transfer the jQuery sortable data, the value here would NOT be an array. Better safe than sorry: Force to empty array if it isnt one.
                                $this->$key = is_array($value) ? $value : [];
                                break;
                            case 'extraContainers':
                                $this->$key = array_map('strval', (array)$value); // a config saved before the strval above may hold numbers
                                break;
                            case 'settingsVersion':
                                $this::$settingsVersion = $value;
                                break;
                            case 'containerSettings':
                            case 'extraContainerSettings':
                                /**
                                 * Container specific patches
                                 */
                                foreach ($value as $containerName => $containerSettings) {
                                    $paths    = preg_split('/\r?\n|\r/', (string)($containerSettings['exclude'] ?? ''));
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
            $prev  = is_array($saved) ? $saved : [];
            $names = self::dockerNames();
            $post  = self::keepUndrawn($post, $prev, $names); // also when no panels were drawn (Docker down at the draw)
            if (($post['containerOrderMode'] ?? '') === 'unraid' && is_array($saved)) {
                // The lists were shown in the Docker tab order and could not be dragged: keep the custom lists as saved
                foreach (['containerOrder', 'containerGroupOrder'] as $key) {
                    unset($post[$key]);
                    if (isset($saved[$key])) {
                        $post[$key] = $saved[$key];
                    }
                }
            }
            $post = self::keepListedSkip($post, $prev, $names);
            self::store($post + array_intersect_key($prev, $extra));
            return true;
        }
        if (!is_array($saved)) {
            return false; // storing only the extra fields would wipe every other setting
        }
        // Skip? per container (name => yes/no; the schedule keeps the No ones), and the start order as a jQuery sortable string, as settings.php gets containerOrder
        $fields                    = array_intersect_key($post, $extra);
        // array_keys() turns a numeric container name such as 1234 into an int; names compare strictly as strings
        $fields['extraContainers'] = array_map('strval', array_keys(array_filter((array)($post['extraSkip'] ?? []), fn($skip) => $skip === 'no')));
        $fields['extraContainerSettings'] = self::ownExtraValues((array)($post['extraContainerSettings'] ?? []));
        parse_str((string)($post['extraContainerOrder'] ?? ''), $order);
        $fields['extraContainerOrder'] = array_values((array)($order['extraContainerOrder'] ?? []));
        $keep = ($saved['containerOrderMode'] ?? '') === 'unraid'
            ? ['extraContainerOrder'] // shown in the Docker tab order, not draggable: keep the custom list as saved
            : [];
        if (!isset($post['extraSkip'])) {
            // No container rows were drawn (Docker down?); every drawn row posts Skip?, so keep the saved choices
            $keep = ['extraContainers', 'extraContainerOrder', 'extraContainerSettings'];
        }
        foreach ($keep as $key) {
            unset($fields[$key]);
            if (isset($saved[$key])) {
                $fields[$key] = $saved[$key];
            }
        }
        if (isset($post['extraSkip'])) {
            $undrawn = self::undrawnFilter(array_keys((array)$post['extraSkip']), self::dockerNames(), (array)($saved['containerSettings'] ?? []));
            $fields['extraContainers'] = [...$fields['extraContainers'], ...array_values(array_filter(array_map('strval', (array)($saved['extraContainers'] ?? [])), $undrawn))];
            $fields['extraContainerSettings'] += array_filter((array)($saved['extraContainerSettings'] ?? []), $undrawn, ARRAY_FILTER_USE_KEY);
            $fields['extraContainerOrder'] = self::keepPlaces((array)($fields['extraContainerOrder'] ?? []), (array)($saved['extraContainerOrder'] ?? []), $undrawn); // the $keep loop unsets it in Docker tab order when none was saved
        }
        self::store(array_diff_key($saved, $extra) + $fields);
        return true;
    }

    /** Skip new containers? decides only for containers added after the Save that changes it: a listed container with no Skip? choice posted or saved gets the old value as its own */
    private static function keepListedSkip(array $post, array $saved, ?array $names) {
        self::$skipKeptWhy = null;
        $old               = ($saved['defaults']['skip'] ?? 'no') === 'yes' ? 'yes' : 'no';
        if ((($post['defaults']['skip'] ?? 'no') === 'yes' ? 'yes' : 'no') === $old) {
            return $post;
        }
        $post['defaults'] = (array)($post['defaults'] ?? []);
        if ($names === null) {
            self::$skipKeptWhy        = ABHelper::isDockerRunning() ? 'Docker did not list its containers' : 'Docker is not running';
            $post['defaults']['skip'] = $old;
            return $post;
        }
        $rows  = (array)($post['containerSettings'] ?? []);
        $unset = array_fill_keys(array_keys(get_class_vars(self::class)['defaults']), ''); // '' = use the default; exclude is saved as text
        foreach ($names as $name) {
            $row = (array)($rows[$name] ?? $unset); // none posted or saved (keepUndrawn put the saved ones back), e.g. added since the page was drawn
            if (!in_array($row['skip'] ?? '', ['yes', 'no'], true)) {
                $row['skip'] = $old; // also a saved row with no choice, which the new value would otherwise decide
            }
            $rows[$name] = $row;
        }
        $post['containerSettings'] = $rows;
        return $post;
    }

    /** Names of the containers Docker has, dead and unreadable ones too (getDockerContainers leaves those out from Unraid 7.2); null when Docker is stopped or did not answer with a whole list */
    private static function dockerNames() {
        if (!ABHelper::isDockerRunning()) {
            return null;
        }
        require_once '/usr/local/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php';
        $body = '';
        ob_start(); // getDockerJSON echoes a socket failure, which would land in the page
        (new \DockerClient())->getDockerJSON('/containers/json?all=1', 'GET', $status, function ($line) use (&$body) { $body .= $line; }, true); // HTTP/1.0: no chunk lines
        ob_end_clean();
        $list = json_decode($body); // objects stay objects, so only a JSON list is an array: a cut or garbled read is not
        if ($status !== true || !is_array($list)) { // true: HTTP 2xx (DockerClient::$codes)
            return null;
        }
        $names = [];
        foreach ($list as $ct) {
            // Its own name: a link alias ("/app/redis") has a second slash, and docker ps leaves it out too
            if ($own = preg_grep('#^/[^/]+$#', (array)(((array)$ct)['Names'] ?? []))) {
                $names[] = substr(reset($own), 1);
            }
        }
        return $names;
    }

    /** Settings tab: puts back what the page could not draw of a container Docker still has (of every one when its list cannot be read): its saved row, and its places in the start order and its group's */
    private static function keepUndrawn(array $post, array $saved, ?array $names) {
        $rows    = (array)($post['containerSettings'] ?? []);
        $undrawn = self::undrawnFilter(array_keys($rows), $names, (array)($saved['containerSettings'] ?? []));
        if ($kept = array_filter((array)($saved['containerSettings'] ?? []), $undrawn, ARRAY_FILTER_USE_KEY)) {
            $post['containerSettings'] = $rows + $kept;
        }
        $post['containerOrder'] = self::keepPlaces((array)($post['containerOrder'] ?? []), (array)($saved['containerOrder'] ?? []), $undrawn);
        foreach ((array)($saved['containerGroupOrder'] ?? []) as $group => $order) {
            if ($places = self::keepPlaces((array)($post['containerGroupOrder'][$group] ?? []), (array)$order, $undrawn)) {
                $post['containerGroupOrder'][$group] = $places;
            }
        }
        return $post;
    }

    /** Accepts a saved name the page did not draw ($drawn) that Docker still has (every one when $names is null), and the start order entry of such a container's group */
    private static function undrawnFilter(array $drawn, ?array $names, array $savedRows) {
        $drawn = array_flip(array_map('strval', $drawn));
        $has   = $names === null ? null : array_flip($names);
        $kept  = fn($name) => !isset($drawn[(string)$name]) && ($has === null || isset($has[(string)$name]));
        $groups = [];
        foreach ($savedRows as $name => $row) {
            if ($kept($name) && ($group = self::groupName(((array)$row)['group'] ?? '')) !== '') {
                $groups['__grp__' . $group] = true; // a group's start order entry, as ABHelper::sortContainers reads it
            }
        }
        return fn($entry) => $kept($entry) || isset($groups[(string)$entry]);
    }

    /** $order with each entry of $saved that $keep accepts and $order lacks put back after the entry it followed in $saved */
    private static function keepPlaces(array $order, array $saved, callable $keep) {
        $order = array_values($order);
        $at    = 0;
        foreach ($saved as $entry) {
            $found = array_search((string)$entry, $order, true);
            if ($found !== false) {
                $at = $found + 1;
            } elseif ($keep((string)$entry)) {
                array_splice($order, $at++, 0, [(string)$entry]);
            }
        }
        return $order;
    }

    /** Only what each extra panel sets: '' is "Same as Settings tab", and the exclusion box counts only as an Own list */
    private static function ownExtraValues(array $rows) {
        $kept = [];
        foreach ($rows as $name => $row) {
            $row = (array)$row;
            if (($row['excludeOwn'] ?? '') !== 'yes') {
                unset($row['exclude']); // the box shows the Settings tab's list then
            }
            if ($row = array_filter($row, fn($value) => $value !== '' && $value !== [])) {
                $kept[$name] = $row;
            }
        }
        return $kept;
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

        $settings['group'] = self::groupName($settings['group']);

        return $settings;
    }

    /** A group's name as the lists and the start order use it: only word characters of what was typed */
    public static function groupName($group) {
        return preg_replace('/[\W]/', '', (string)$group);
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

    /** Backup type Flash only: the run backs up the flash drive and nothing else, and stops no container */
    public function flashOnly(): bool {
        return $this->backupMethod === 'flashOnly';
    }

    /** The file a Flash only run puts into its set when it creates it (backup.php), from the type that run loaded */
    const FLASH_ONLY_MARKER = '.flash-only';

    /** Whether a set was made by a Flash only run: its marker, written at the start, so a Settings save during the run cannot relabel it */
    public static function flashOnlySet(string $set): bool {
        return file_exists("$set/" . self::FLASH_ONLY_MARKER);
    }

    /** The type a schedule copying this one backs its containers up with: Flash only has none, so it gives the default */
    public function containerBackupMethod(): string {
        return $this->flashOnly() ? self::DEFAULT_BACKUP_METHOD : (string)$this->backupMethod;
    }

    /** Whether the run backs up the flash drive: always with Flash only */
    public function backsUpFlash(): bool {
        return $this->flashOnly() || $this->flashBackup === 'yes';
    }

    /** Whether the run backs up VM meta: never with Flash only */
    public function backsUpVMMeta(): bool {
        return !$this->flashOnly() && $this->backupVMMeta === 'yes';
    }

    /** Whether the run backs up extra files: never with Flash only */
    public function backsUpExtraFiles(): bool {
        return !$this->flashOnly() && !empty($this->includeFiles);
    }

    /** These settings as $schedule runs them: 'extra' swaps in its frequency, destination, retention, start order, per-container and run-wide settings */
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
        foreach (self::RETENTION as $field) {
            $settings->$field = $this->{'extra' . ucfirst($field)};
        }
        $settings->containerOrder         = $this->extraContainerOrder ?: $this->containerOrder;
        $settings->containerSettings      = [];
        foreach (array_unique(array_merge(array_keys($this->containerSettings), $this->extraContainers)) as $name) {
            $name = (string)$name; // array keys of numeric names are ints
            $own  = $this->extraContainerSettings[$name] ?? [];
            $set = array_filter(array_intersect_key($own, array_flip(self::EXTRA_CONTAINER_KEYS)), fn($value) => $value !== '');
            if (($own['excludeOwn'] ?? '') === 'yes') {
                $set['exclude'] = (array)($own['exclude'] ?? []); // an empty own list means no exclusions
            }
            foreach (self::EXTRA_DEFAULTS as $key => $field) {
                if ($this->$field !== '' && !isset($set[$key])) {
                    $set[$key] = $this->$field; // this tab's default beats the Settings tab's value for this container
                }
            }
            $set['skip'] = in_array($name, $this->extraContainers, true) ? 'no' : 'yes'; // the Extra tab's Skip?, not the Settings tab's
            $settings->containerSettings[$name] = array_merge($this->containerSettings[$name] ?? [], $set);
        }
        foreach (self::EXTRA_SAME_AS as $mainKey => $extraKey) {
            if ($this->$extraKey !== '') {
                $settings->$mainKey = $this->$extraKey;
            }
        }
        if ($this->extraBackupMethod === '') {
            $settings->backupMethod = $this->containerBackupMethod(); // the extra schedule backs up containers whatever the Settings tab's type
        }
        foreach (self::EXTRA_OWN_YES as $mainKey => $extraKey) {
            $settings->$mainKey = $this->$extraKey === 'yes' ? 'yes' : 'no';
        }
        if ($this->extraFlashBackup === 'yes') {
            $settings->flashBackupCopy = $this->extraFlashBackupCopy;
        }
        if ($this->extraScripts === 'own') {
            foreach (self::SCRIPTS as $script) {
                $settings->$script = $this->{'extra' . ucfirst($script)};
            }
        }
        $settings->includeFiles = $this->extraIncludeFiles;
        if ($this->extraGlobalExclusionsOwn === 'yes') {
            $settings->globalExclusions = $this->extraGlobalExclusions;
        }
        return $settings;
    }

    /** For the extra run's logs: a one-line summary, each run-wide setting with where it came from, and each included container's own overrides */
    public function scheduleSummary() {
        $names = [
            'backupMethod' => ['stopAll' => 'Stop all', 'oneAfterTheOther' => 'For each container'],
            'compression'  => ['no' => 'No', 'yes' => 'Yes, normal', 'yesMulticore' => 'Yes, multicore'],
            'notification' => [ABHelper::LOGLEVEL_ERR => 'Errors only', ABHelper::LOGLEVEL_WARN => 'Warnings and errors', 'disabled' => 'Disabled'],
            'compressionCpuLimit' => ['0' => 'All cores'],
        ];
        $labels = ['backupMethod' => 'Backup type', 'snapshotMode' => 'Snapshots', 'compression' => 'Compression', 'compressionCpuLimit' => 'Cores', 'flashBackup' => 'Flash backup', 'backupVMMeta' => 'VM meta', 'notification' => 'Notifications', 'successLogWanted' => 'Success notification', 'updateLogWanted' => 'Update notification'];
        $run      = $this->forSchedule('extra');
        $source   = fn($own) => $own ? ' (own)' : ' (Settings tab)';
        $settings = [
            'Destination' => $run->destination,
            'Retention'   => ABRetention::describe($run),
            'Containers'  => $this->extraContainers ? implode(', ', $this->extraContainers) : 'none',
            'Start order' => $this->containerOrderMode === 'unraid' ? 'Docker tab' : ($this->extraContainerOrder ? 'own' : 'Settings tab'),
        ];
        foreach (self::EXTRA_SAME_AS as $mainKey => $extraKey) {
            $value                     = (string)$run->$mainKey;
            $settings[$labels[$mainKey]] = ($names[$mainKey][$value] ?? ['yes' => 'Yes', 'no' => 'No'][$value] ?? $value) . $source($this->$extraKey !== '');
        }
        if ($this->flashOnly() && $this->extraBackupMethod === '') {
            $settings['Backup type'] = ($names['backupMethod'][$run->backupMethod] ?? $run->backupMethod) . ' (default: the Settings tab is Flash only)';
        }
        foreach (self::EXTRA_OWN_YES as $mainKey => $extraKey) {
            $settings[$labels[$mainKey]] = ($run->$mainKey === 'yes' ? 'Yes' : 'No') . ' (own)';
        }
        $settings['Flash copy']       = $run->flashBackup === 'yes' ? ($run->flashBackupCopy ?: 'none') : '-';
        $hooks                        = array_combine(self::SCRIPTS, ['Pre-run', 'Pre-backup', 'Pre-container', 'Post-container', 'Post-backup', 'Post-run']);
        $settings['Scripts']          = (implode(', ', array_filter(array_map(fn($script) => $run->$script ? "{$hooks[$script]}: {$run->$script}" : '', self::SCRIPTS))) ?: 'none') . $source($this->extraScripts === 'own');
        $settings['Extra files']      = implode(', ', $run->includeFiles) ?: 'none';
        $settings['Global exclusions'] = (implode(', ', $run->globalExclusions) ?: 'none') . $source($this->extraGlobalExclusionsOwn === 'yes');
        $defaultLabels = ['updateContainer' => 'Default: update after backup', 'dontStop' => 'Default: skip stopping', 'verifyBackup' => 'Default: verify', 'ignoreBackupErrors' => 'Default: ignore errors'];
        foreach (self::EXTRA_DEFAULTS as $key => $field) {
            $settings[$defaultLabels[$key]] = ($this->$field !== '' ? $this->$field . ' (own)' : 'per container, else ' . $this->defaults[$key] . ' (Settings tab)');
        }

        $containerLabels = array_combine(self::EXTRA_CONTAINER_KEYS, ['External volumes', 'Update after backup', 'Skip backup', 'Verify', 'Ignore errors', 'Skip stopping']);
        $overrides       = [];
        foreach ($this->extraContainers as $name) {
            $own = $this->extraContainerSettings[$name] ?? [];
            $set = [];
            foreach (array_filter(array_intersect_key($own, $containerLabels), fn($value) => $value !== '') as $key => $value) {
                $set[$containerLabels[$key]] = $value;
            }
            if (($own['excludeOwn'] ?? '') === 'yes') {
                $set['Exclusions'] = implode(', ', (array)($own['exclude'] ?? [])) ?: 'none';
            }
            $overrides[$name] = $set ?: 'none, all from the Settings tab';
        }

        $line = 'Extra schedule: ' . count($this->extraContainers) . (count($this->extraContainers) == 1 ? ' container' : ' containers') . ' into ' . $run->destination . ', ' . $settings['Retention'] . ', ' . $settings['Backup type'] . ', snapshots ' . $settings['Snapshots'];
        return ['line' => $line, 'settings' => $settings, 'overrides' => $overrides];
    }

    /** The DockerClient containers this schedule backs up: all of them, or the ones the extra schedule includes */
    public function scheduleContainers($containers) {
        if ($this->schedule !== 'extra') {
            return $containers;
        }
        return array_values(array_filter($containers ?: [], fn($container) => in_array($container['Name'], $this->extraContainers, true)));
    }

    /** Containers the extra schedule includes that DockerClient no longer lists, e.g. renamed; [] for the main schedule */
    public function scheduleMissing($containers) {
        if ($this->schedule !== 'extra') {
            return [];
        }
        return array_values(array_diff($this->extraContainers, array_column($containers ?: [], 'Name')));
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
// When the command ends, its shell removes the PID file if it still holds that command's PID: Abort kills the PID in it, which must never be a finished command's reused one
ABSettings::$externalCmdPidCapture = sprintf('& abpid=$!; echo $abpid > %1$s; wait $abpid; rc=$?; [ "$(cat %1$s 2>/dev/null)" = "$abpid" ] && rm -f %1$s; exit $rc', escapeshellarg(ABSettings::$tempFolder . '/' . ABSettings::$stateExtCmd));

if (!file_exists(ABSettings::$tempFolder)) {
    mkdir(ABSettings::$tempFolder);
}
