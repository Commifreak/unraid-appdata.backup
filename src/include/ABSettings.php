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
    public array $defaults = [
        'verifyBackup'       => 'yes',
        'ignoreBackupErrors' => 'no',
        'updateContainer'    => 'no',

        // The following are hidden, container special default settings
        'skip'               => 'no',
        'exclude'            => '',
        'dontStop'           => 'no',
        'backupExtVolumes'   => 'no'
    ];
    public string $flashBackup = 'yes';
    public string $notification = 'errors';
    public string $backupFrequency = 'disabled';
    public string|int $backupFrequencyWeekday = '1';
    public string|int $backupFrequencyDayOfMonth = '1';
    public string|int $backupFrequencyHour = '0';
    public string|int $backupFrequencyMinute = '0';
    public string $backupFrequencyCustom = '';
    public array $containerSettings = [];
    public array $containerOrder = [];
    public string $preRunScript = '';
    public string $preBackupScript = '';
    public string $postBackupScript = '';
    public string $postRunScript = '';
    public string $includeFiles = '';
    public string $backupVMMeta = 'yes';

    public function __construct() {
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
                                $sources = explode("\r\n", $value);
                                foreach ($sources as $sourceKey => $source) {
                                    $sources[$sourceKey] = rtrim($source, '/');
                                }
                                $this->$key = $sources;
                                break;
                            case 'containerOrder':
                                // HACK - if something goes wrong while we transfer the jQuery sortable data, the value here would NOT be an array. Better safe than sorry: Force to empty array if it isnt one.
                                $this->$key = is_array($value) ? $value : [];
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
                    if ($sortKey) {
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
                $this->containerSettings[$name][$setting] = '';
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
        return $settings;
    }

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
                $cronSettings .= '';
        }

        if (!empty($cronSettings)) {
            $cronSettings .= ' php ' . dirname(__DIR__) . '/scripts/backup.php > /dev/null 2>&1';
            file_put_contents(ABSettings::$pluginDir . '/' . ABSettings::$cronFile, $cronSettings . PHP_EOL);

            // Let dcron know our changes via update_cron
            exec("update_cron");
        } elseif (file_exists(ABSettings::$pluginDir . '/' . ABSettings::$cronFile)) {
            unlink(ABSettings::$pluginDir . '/' . ABSettings::$cronFile);
        }
    }

}

// Init some default values
if (str_contains(__DIR__, 'appdata.backup.beta')) {
    ABSettings::$appName    .= '.beta';
    ABSettings::$pluginDir  .= '.beta';
    ABSettings::$tempFolder .= '.beta';
    ABSettings::$cronFile   .= '_beta';
    ABSettings::$supportUrl = 'https://forums.unraid.net/topic/136995-pluginbeta-appdatabackup/';
}
ABSettings::$externalCmdPidCapture = '& echo $! > ' . escapeshellarg(ABSettings::$tempFolder . '/' . ABSettings::$stateExtCmd) . ' && wait $!';