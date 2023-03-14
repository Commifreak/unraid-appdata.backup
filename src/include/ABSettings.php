<?php

namespace unraid\plugins;

/**
 * This class offers a convenient way to retrieve settings
 */
class ABSettings {

    public static $pluginDir = '/boot/config/plugins/appdata.backup';
    public static $settingsFile = 'config.json';


    public string|null $backupMethod = 'oneAfterTheOther';
    public string|int $deleteOldBackupsDays = '7';
    public string|int $keepBackupsDays = '';
    public string $source = '/mnt/cache/appdata';
    public string $destination = '';
    public string $compression = 'yes';
    public array $defaults = ['verifyBackup' => 'yes', 'ignoreBackupErrors' => 'no', 'updateContainers' => 'no'];
    public string $separateArchives = 'yes';
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
    }

    public static function getConfigPath() {
        return self::$pluginDir . DIRECTORY_SEPARATOR . self::$settingsFile;
    }

}