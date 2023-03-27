<?php

namespace unraid\plugins\AppdataBackup;

require_once __DIR__ . '/ABSettings.php';

/**
 * This class offers a convenient way to retrieve settings
 */
class ABSettings {

    public static $appName = 'appdata.backup';
    public static $dockerIniFile = '/boot/config/docker.cfg';
    public static $pluginDir = '/boot/config/plugins/appdata.backup';
    public static $settingsFile = 'config.json';
    public static $cronFile = '/etc/cron.d/appdata_backup';

    public static $tempFolder = '/tmp/appdata.backup';

    public static $logfile = 'ab.log';
    public static $debugLogFile = 'ab.debug.log';

    public static $stateFileScriptRunning = 'running';
    public static $stateFileAbort = 'abort';

    public static $emhttpVars = '/var/local/emhttp/var.ini';


    public string|null $backupMethod = 'oneAfterTheOther';
    public string|int $deleteBackupsOlderThan = '7';
    public string|int $keepMinBackups = '3';
    public string $destination = '';
    public string $compression = 'yes';
    public array $defaults = ['verifyBackup' => 'yes', 'ignoreBackupErrors' => 'no', 'updateContainer' => 'no'];
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

    public function __construct() {
        $sFile = self::getConfigPath();
        if (file_exists($sFile)) {
            $config = json_decode(file_get_contents($sFile), true);
            if ($config) {
                foreach ($config as $key => $value) {
                    if (property_exists($this, $key)) {
                        $this->$key = $value;
                    }
                }
            }
        }
        ABHelper::$targetLogLevel = $this->notification;

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
            return array_merge($this->defaults, ['skip' => 'no', 'exclude' => [], 'dontStop' => 'no']);
        }

        $settings = $this->containerSettings[$name];

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
        switch ($this->backupFrequency) {
            case 'custom':
                $cronSettings = $this->backupFrequencyCustom;
                break;
            case 'daily':
                $cronSettings = $this->backupFrequencyMinute . " " . $this->backupFrequencyHour . " * * *";
                break;
            case 'weekly':
                $cronSettings = $this->backupFrequencyMinute . " " . $this->backupFrequencyHour . " * * " . $this->backupFrequencyWeekday;
                break;
            case 'monthly':
                $cronSettings = $this->backupFrequencyMinute . " " . $this->backupFrequencyHour . " " . $this->backupFrequencyDayOfMonth . " * *";
                break;
            default:
                $cronSettings = '';
        }

        if (!empty($cronSettings)) {
            $cronSettings .= ' php ' . dirname(__DIR__) . '/scripts/backup.php';
            file_put_contents(ABSettings::$cronFile, $cronSettings);

            // Restart dcron, that forces a re-read of /etc/cron.d. Otherwise, we have to wait one hour, because dcron read cron.d files once an hour.
            exec("/etc/rc.d/rc.crond restart");
        } elseif (file_exists(ABSettings::$cronFile)) {
            unlink(ABSettings::$cronFile);
        }
    }

}

// Init some default values
if (str_contains(__DIR__, 'appdata.backup.beta')) {
    ABSettings::$appName    .= '.beta';
    ABSettings::$pluginDir  .= '.beta';
    ABSettings::$tempFolder .= '.beta';
    ABSettings::$cronFile   .= '_beta';
}