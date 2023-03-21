<?php

namespace unraid\plugins\AppdataBackup;

/**
 * This class offers a convenient way to retrieve settings
 */
class ABSettings {

    public static $pluginDir = '/boot/config/plugins/appdata.backup';
    public static $settingsFile = 'config.json';

    public static $tempFolder = '/tmp/appdata.backup';

    public static $logfile = 'ab.log';
    public static $debugLogFile = 'ab.debug.log';

    public static $stateFileBackupInProgress = 'backupInProgress';
    public static $stateFileRestoreInProgress = 'restoreInProgress';
    public static $stateFileVerifyInProgress = 'verifyInProgress';

    public static $emhttpVars = '/var/local/emhttp/var.ini';


    public string|null $backupMethod = 'oneAfterTheOther';
    public string|int $deleteBackupsOlderThan = '7';
    public string|int $keepMinBackups = '3';
    public string $source = '/mnt/cache/appdata';
    public string $destination = '';
    public string $compression = 'yes';
    public array $defaults = ['verifyBackup' => 'no', 'ignoreBackupErrors' => 'no', 'updateContainer' => 'no'];
    public string $flashBackup = 'yes';
    public string $notification = 'errors';
    public string $backupFrequency = 'daily';
    public string|int $backupFrequencyWeekday = '';
    public string|int $backupFrequencyDayOfMonth = '';
    public string|int $backupFrequencyHour = '';
    public string|int $backupFrequencyMinute = '';
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
            return array_merge($this->defaults, ['skip' => 'no', 'exclude' => []]);
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

}