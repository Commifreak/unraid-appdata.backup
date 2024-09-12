<?php

use unraid\plugins\AppdataBackup\ABSettings;

require_once(dirname(__DIR__) . '/include/ABSettings.php');

echo "Checking for needed migrations..." . PHP_EOL;

$abSettings = new ABSettings(); # By just instantiate a new instance, migrations will be checked.
exit(0);