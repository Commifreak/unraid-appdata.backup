<?php

use unraid\plugins\AppdataBackup\ABSettings;

// CLI only: nginx runs any .php under the plugin folder for a logged-in GET.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once(dirname(__DIR__) . '/include/ABSettings.php');

echo "Checking cron." . PHP_EOL;

try {
    $abSettings = new ABSettings();
    list($code, $out) = $abSettings->checkCron();

    if ($code == 0) {
        echo "Checking cron succeeded!" . PHP_EOL;
    } else {
        echo "Error occurred: " . implode('; ', $out);
    }
} catch (Exception $e) {
    echo "Exception occurred! => " . print_r($e->getMessage());
    exit(1);
}



exit(0);