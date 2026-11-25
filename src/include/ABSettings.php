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

    /** The config.json settings the last new ABSettings() could not read, so they kept their defaults (see unreadableWarning) */
    public static array $unreadable = [];
    /** Whether the last new ABSettings() could not read config.json at all, as against reading it as no settings */
    private static bool $fileUnread = false;
    /** Why storeForm() stored nothing: the saved settings could not be read to keep the rest, or config.json could not be written */
    const NOT_READ = 'read', NOT_WRITTEN = 'written';
    /** What both tabs show for NOT_WRITTEN */
    const NOT_WRITTEN_TEXT = 'Not saved: config.json on the flash drive could not be written. Check the flash drive, then Save again.';
    /** $unreadable's item when config.json holds no settings at all */
    const WHOLE_FILE = 'the whole file';

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

    /** 'stopAll', 'oneAfterTheOther', or 'flashOnly' (no container is stopped or backed up, see flashOnly()); other saved text is named (backupMethod()) */
    public string $backupMethod = self::DEFAULT_BACKUP_METHOD;
    public string $snapshotMode = 'no';
    /** 'age' (deleteBackupsOlderThan, keepMinBackups) or 'gfs' (ABRetention::PERIODS); other saved text turns retention off (ABRetention::unreadable) */
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

        self::$unreadable = [];
        $config           = self::savedConfig();
        self::$fileUnread = $config === false;
        if (!is_array($config)) {
            self::$unreadable[] = self::WHOLE_FILE;
            $config             = [];
        }
        foreach ($config as $key => $value) {
            if (property_exists($this, $key)) {
                $this->load((string)$key, $value);
            }
        }
        if ($this->extraSchedule !== 'yes') { // no extra runs, and no Extra schedule tab to set them on; a main run's retention still keeps out of extraDestination
            self::$unreadable = array_values(array_filter(self::$unreadable, fn($item) => !self::onExtraTab($item) || $item === 'extraDestination'));
            self::$unreadable = array_map(fn($item) => $item === 'extraDestination' ? "$item (Use an extra schedule? Yes shows its tab)" : $item, self::$unreadable);
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

    /** How load() reads a setting PHP would refuse or misread as saved: setting => reader. A reader's null keeps the default and is named */
    private const READERS = ['defaults' => 'defaultsRow', 'allowedSources' => 'pathLines', 'includeFiles' => 'pathLines', 'globalExclusions' => 'pathLines',
        'extraIncludeFiles' => 'pathLines', 'extraGlobalExclusions' => 'pathLines', 'containerOrderMode' => 'orderMode', 'containerOrder' => 'order',
        'extraContainerOrder' => 'order', 'containerGroupOrder' => 'groupOrder', 'extraContainers' => 'containerNames', 'containerSettings' => 'containerRows',
        'extraContainerSettings' => 'containerRows', 'backupMethod' => 'backupMethod', 'extraBackupMethod' => 'backupMethod',
        'backupFrequency' => 'frequency', 'extraFrequency' => 'frequency', 'extraSchedule' => 'yesNo'];

    /** Sets one saved setting, with PHP's own casts (true to "1"): a value it cannot take keeps the default and is named in $unreadable. A retention value that is neither text nor an int is first turned into its JSON text */
    private function load(string $key, $value) {
        if (in_array(str_starts_with($key, 'extra') ? lcfirst(substr($key, 5)) : $key, self::RETENTION, true) && !is_string($value) && !is_int($value)) {
            $value = json_encode($value) ?: var_export($value, true); // a default, or PHP's cast of 1.5 or true to 1, could delete sets; ABRetention::unreadable() names "1.5", "true", "[]" or INF (1e400, which has no JSON) and keeps every set
        }
        if ($key === 'settingsVersion') {
            if (self::isVersion($value)) {
                $this::$settingsVersion = $value;
            } else {
                self::$unreadable[] = $key; // migrateConfig() skipped it
            }
            return;
        }
        try {
            $this->$key = isset(self::READERS[$key]) ? [self::class, self::READERS[$key]]($value, $key) : $value; // a reader's null fails here too: no property it fills takes null
        } catch (\TypeError) { // a type PHP refuses for this property would stop every page and run: named instead
            self::$unreadable[] = $key;
        }
    }

    /** The saved defaults over the built-in ones, read like a container row (row()); null when they are not a map */
    private static function defaultsRow($value) {
        return is_array($value) ? array_merge(get_class_vars(self::class)['defaults'], self::row('defaults', $value)) : null;
    }

    /** A backup type its tab offers: the extra schedule's has no Flash only, but '' (the Settings tab's, which may be Flash only); doBackupMethod() backs up no container for any other */
    private static function backupMethod($value, string $key) {
        return in_array($value, ['stopAll', 'oneAfterTheOther', ...($key === 'extraBackupMethod' ? [''] : ['flashOnly'])], true) ? $value : null;
    }

    /** A frequency both tabs offer: cronTime() writes no line for any other, so checkCron() would drop the saved schedule */
    private static function frequency($value) {
        return in_array($value, ['disabled', 'daily', 'weekly', 'monthly', 'custom'], true) ? $value : null;
    }

    /** 'yes' or 'no', as the select offers: checkCron() would read other text as no and drop the saved extra line */
    private static function yesNo($value) {
        return in_array($value, ['yes', 'no'], true) ? $value : null;
    }

    /** A settingsVersion migrateConfig() can count up from: a whole number from 1, or its digits as the Settings tab posts it */
    private static function isVersion($value) {
        return (is_int($value) || (is_string($value) && ctype_digit($value))) && (int)$value >= 1;
    }

    /** 'unraid' (the Docker tab's start order), or 'custom' for any other value */
    private static function orderMode($value) {
        return $value === 'unraid' ? 'unraid' : 'custom';
    }

    /** A saved start order's container names: anything else in it, or an order that is not an array, is left out, so those start in the default order */
    private static function order($value) {
        return is_array($value) ? array_filter($value, fn($name) => is_string($name) || is_int($name)) : [];
    }

    /** Each group's start order (order()) */
    private static function groupOrder($value) {
        return is_array($value) ? array_map(fn($order) => self::order($order), $value) : [];
    }

    /** Saved container names as strings (storeForm saves a list of strings; an older config may hold numbers); null for anything but an array of them */
    private static function containerNames($value) {
        return !is_array($value) || array_filter($value, fn($name) => !is_string($name) && !is_int($name)) ? null : array_map('strval', $value);
    }

    /** Saved container rows (row()); a row that is not a map is dropped and named in $unreadable; null when the rows are not a map */
    private static function containerRows($rows, string $key) {
        if (!is_array($rows)) {
            return null;
        }
        foreach ($rows as $name => $row) {
            if (!is_array($row)) {
                self::$unreadable[] = "$key ($name)";
                unset($rows[$name]);
                continue;
            }
            $rows[$name] = self::row("$key ($name)", $row);
        }
        return $rows;
    }

    /** A container row, or the defaults, with its exclude as a list of paths (one it cannot read is empty) and any other setting not held as text or a number left out, so the default applies; each that held a value is named in $unreadable */
    private static function row(string $item, array $row) {
        $exclude = $row['exclude'] ?? '';
        $paths   = self::pathLines(in_array($exclude, [false, []], true) ? '' : $exclude); // an empty one held no setting to lose, as below
        if ($paths === null) {
            self::$unreadable[] = "$item exclude";
        }
        $row['exclude'] = $paths ?? [];
        foreach ([...array_keys(get_class_vars(self::class)['defaults']), 'excludeOwn'] as $field) { // excludeOwn: the extra rows'
            if ($field === 'exclude' || !array_key_exists($field, $row) || is_string($row[$field]) || is_int($row[$field])) {
                continue;
            }
            if (!empty($row[$field])) {
                self::$unreadable[] = "$item $field"; // an empty one held no setting to lose
            }
            unset($row[$field]);
        }
        return $row;
    }

    /** One path per non-empty line of the text, or of a list's texts, without its trailing slash; null for anything else, or a list holding anything else */
    private static function pathLines($value) {
        if (is_array($value)) {
            if (array_filter($value, fn($line) => !is_string($line))) {
                return null;
            }
            $value = implode("\n", $value);
        } elseif (!is_string($value)) {
            return null;
        }
        $paths = [];
        foreach (preg_split('/\r?\n|\r/', $value) as $path) {
            if (trim($path) === '') {
                continue; // Skip empty lines
            }
            $paths[] = rtrim($path, '/');
        }
        return $paths;
    }

    /** Whether an $unreadable item is set on the Extra schedule tab rather than the Settings tab */
    private static function onExtraTab(string $item) {
        return in_array(explode(' ', $item)[0], self::EXTRA_FIELDS, true);
    }

    /** The warning about $unreadable, for the Settings tab and each run's log; '' when every saved setting was read */
    public static function unreadableWarning() {
        if (in_array(self::WHOLE_FILE, self::$unreadable, true)) {
            return self::$fileUnread // a Save is refused while it cannot be read: see storeForm
                ? 'config.json on the flash drive could not be read, so every setting uses its default, and retention keeps every backup set, until it can be read again.'
                : 'config.json could not be read as settings, so every setting uses its default, and retention keeps every backup set, until you set them again and Save on the Settings tab.';
        }
        $extra = array_filter(self::$unreadable, fn($item) => self::onExtraTab($item));
        $tabs  = array_filter(['the Settings tab' => array_diff(self::$unreadable, $extra), 'the Extra schedule tab' => $extra]);
        $where = array_map(fn($tab, $items) => "$tab: " . implode(', ', $items), array_keys($tabs), $tabs);
        return $where ? 'config.json holds settings this version cannot read, so they use their defaults, and retention keeps every backup set, until you set them again and Save on ' . implode('; ', $where) . '.' : '';
    }

    /** Whether the last load could not read a setting matching $pattern, or the whole file */
    public static function unread(string $pattern) {
        return in_array(self::WHOLE_FILE, self::$unreadable, true) || preg_grep($pattern, self::$unreadable);
    }

    /** config.json decoded: [] when there is none, false when it cannot be read, null when it holds no settings (not JSON, or not an object) */
    public static function savedConfig() {
        $sFile = self::getConfigPath();
        if (!file_exists($sFile)) {
            return [];
        }
        if (($raw = @file_get_contents($sFile)) === false) {
            return false;
        }
        $config = json_decode($raw, true);
        return is_array($config) && str_starts_with(ltrim($raw), '{') ? $config : null; // a list decodes to an array too, [] as {} does
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
            $config = self::savedConfig();
            if ($config) { // the constructor names a file that is not settings
                if (!isset($config['settingsVersion'])) {
                    $config['settingsVersion'] = 1; // No version set, set the current one
                }
                if (!self::isVersion($config['settingsVersion'])) {
                    return; // false never counts up, so the loop below would not end; the constructor names it
                }
                for ($curMigrationStep = $config['settingsVersion']; $curMigrationStep < ABSettings::$settingsVersion; $curMigrationStep++) {
                    ABHelper::logger('Found migrations! Running Migration for version ' . $curMigrationStep . ' to ' . ABSettings::$settingsVersion);

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
                        $config['settingsVersion']++;
                        if (!self::store($config)) {
                            ABHelper::logger('ERROR: Migration could not be written to config.json');
                            return; // the next load runs it again
                        }
                        ABHelper::logger('Migration was successful!');
                    } else {
                        ABHelper::logger('ERROR: Migration was NOT successful!');
                    }
                }
            }
        }
    }

    /** Writes config.json through a temporary file, so a failed write leaves the old one; false when it could not */
    public static function store(array $config) {
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return $json !== false && self::replaceFile(self::getConfigPath(), $json);
    }

    /** Replaces $path with $text through a temporary file beside it, so a failed or short write leaves the old file; false when it could not */
    private static function replaceFile(string $path, string $text) {
        $tmp = "$path." . getmypid() . '.tmp'; // its own per process: two writers (a Save, a run's migration, checkCron) may replace one file at once
        if (@file_put_contents($tmp, $text) !== strlen($text) || !@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /** Stores a posted form: the Settings tab's replaces all but EXTRA_FIELDS, the Extra schedule tab's (extraScheduleForm) only those; null when stored, else NOT_READ or NOT_WRITTEN */
    public static function storeForm(array $post) {
        $saved = self::savedConfig();
        if ($saved === false || ($saved === null && isset($post['extraScheduleForm']))) {
            return self::NOT_READ; // only the Settings tab's Save replaces a config.json that holds no settings, as the warning asks
        }
        $saved ??= [];
        $extra   = array_flip(self::EXTRA_FIELDS);
        if (!isset($post['extraScheduleForm'])) {
            $prev  = $saved;
            $names = self::dockerNames();
            $post  = self::keepUndrawn($post, $prev, $names); // also when no panels were drawn (Docker down at the draw)
            if (($post['containerOrderMode'] ?? '') === 'unraid') {
                // The lists were shown in the Docker tab order and could not be dragged: keep the custom lists as saved
                foreach (['containerOrder', 'containerGroupOrder'] as $key) {
                    unset($post[$key]);
                    if (isset($saved[$key])) {
                        $post[$key] = $saved[$key];
                    }
                }
            }
            $post = self::keepListedSkip($post, $prev, $names);
            return self::store($post + array_intersect_key($prev, $extra)) ? null : self::NOT_WRITTEN;
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
        return self::store(array_diff_key($saved, $extra) + $fields) ? null : self::NOT_WRITTEN;
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

    /** The saved cron lines of one schedule when the last load could not read one of its settings ($fields, a pattern) or the whole file: a default could stop or move it. $lines is the cron file's ([] for none, false when it cannot be read, which gives null) */
    private static function keptCronLines(string $fields, bool $extra, $lines) {
        if (!self::unread($fields)) {
            return [];
        }
        return $lines === false ? null : array_values(array_filter($lines, fn($line) => str_contains($line, '/scripts/backup.php') && str_contains($line, ' extra ') === $extra)); // older versions wrote the main line without 'scheduled'
    }

    /**
     * @return array
     */
    public function checkCron() {
        $cronPath = self::$pluginDir . '/' . self::$cronFile;
        $saved    = is_file($cronPath) ? @file($cronPath, FILE_IGNORE_NEW_LINES) : []; // read once, so both schedules keep lines of the same file
        $lines    = [];
        // 'scheduled' makes a run wait for a running job instead of being refused
        foreach (['backupFrequency' => ['scheduled', '/^backupFrequency/'], 'extraFrequency' => ['scheduled extra', '/^(extraFrequency|extraSchedule$)/']] as $prefix => [$args, $fields]) {
            $kept = self::keptCronLines($fields, $prefix === 'extraFrequency', $saved);
            if ($kept === null) {
                return [1, ['The cron file ' . self::$pluginDir . '/' . self::$cronFile . ' could not be read, so the schedules were left as they were']];
            }
            if ($kept) {
                array_push($lines, ...$kept);
                continue;
            }
            $time = $prefix === 'extraFrequency' && $this->extraSchedule !== 'yes' ? '' : $this->cronTime($prefix);
            if ($time !== '') {
                $lines[] = $time . ' php ' . dirname(__DIR__) . '/scripts/backup.php ' . $args . ' > /dev/null 2>&1';
            }
        }

        $written = $lines ? self::replaceFile($cronPath, '# Appdata.Backup cron settings' . PHP_EOL . implode(PHP_EOL, $lines) . PHP_EOL) : !file_exists($cronPath) || @unlink($cronPath);
        if (!$written) {
            return [1, ["The cron file $cronPath could not be written, so the schedules may not match these settings"]];
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
// Abort writes its file before it reads the PID file, so a command whose PID it missed sees the abort file here and is killed
ABSettings::$externalCmdPidCapture = sprintf('& abpid=$!; echo $abpid > %1$s; [ -e %2$s ] && kill $abpid 2>/dev/null; wait $abpid; rc=$?; [ "$(cat %1$s 2>/dev/null)" = "$abpid" ] && rm -f %1$s; exit $rc',
    escapeshellarg(ABSettings::$tempFolder . '/' . ABSettings::$stateExtCmd), escapeshellarg(ABSettings::$tempFolder . '/' . ABSettings::$stateFileAbort));

if (!file_exists(ABSettings::$tempFolder)) {
    mkdir(ABSettings::$tempFolder);
}
