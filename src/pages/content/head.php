<?php

use unraid\plugins\AppdataBackup\ABCron;
use unraid\plugins\AppdataBackup\ABSettings;

// include_once from each tab: the three tabs render on one page, so the stylesheet loads once

/** An option for a saved schedule $field value its list does not offer, so a Save that leaves it posts it as saved, and abCronCheck() refuses that Save; '' when the list offers it */
function abUnofferedOption(string|int $value, string $field): string {
    return ABCron::accepts($field, $value) ? '' : '<option value="' . htmlspecialchars((string)$value) . '" data-ab-unoffered>' . htmlspecialchars((string)$value) . '</option>';
}
?>
<link type="text/css" rel="stylesheet" href="<?php autov('/plugins/' . ABSettings::$appName . '/pages/content/ab.css') ?>">
