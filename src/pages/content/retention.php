<?php

use unraid\plugins\AppdataBackup\ABRetention;
use unraid\plugins\AppdataBackup\ABSettings;

/** The Retention select and each choice's fields, on the Settings tab or the Extra schedule tab ($extra); abRetention() in settings.php switches them */
function abRetentionRows(ABSettings $abSettings, bool $extra): string {
    $name = fn(string $field) => $extra ? 'extra' . ucfirst($field) : $field;
    $mode = $abSettings->{$name('retentionMode')};
    $row  = function (string $field, string $help) use ($abSettings, $extra, $name) {
        $help  = $extra ? 'Works like <b>' . ABRetention::label($field) . '</b> on the Settings tab, for the extra destination only.' : $help;
        $label = ABRetention::label($field, $extra);
        return "<dl>\n    <dt><b>$label:</b></dt>\n    <dd><input id='{$name($field)}' name='{$name($field)}' type='number' min='0' max='999999' value='"
            . htmlspecialchars((string)$abSettings->{$name($field)}, ENT_QUOTES) . "' placeholder='Leave empty to disable'/></dd>\n</dl>\n"
            . "<blockquote class='inline_help'><p>$help</p></blockquote>\n";
    };
    $select = $name('retentionMode');
    $label  = $extra ? 'Extra schedule: retention' : 'Retention';
    $html   = "<dl>\n    <dt><b>$label:</b></dt>\n    <dd><select id='$select' name='$select' onchange='abRetention(this);'>\n";
    foreach (['age' => 'By age', 'gfs' => 'Grandfather-father-son (GFS)'] as $value => $text) {
        $html .= "        <option value='$value'" . ($mode === $value ? ' selected' : '') . ">$text</option>\n";
    }
    $html .= "    </select></dd>\n</dl>\n<blockquote class='inline_help'><p>" . ($extra ? 'Works like <b>Retention</b> on the Settings tab, for the extra destination only.'
        : 'After a run with no errors, deletes the backup sets this does not keep. <b>By age</b> deletes sets older than a number of days. <b>Grandfather-father-son</b> keeps the newest good set of each day, week, month and year, for as many of each as you set, and deletes the rest. <b>Flash only</b> sets and the others are counted and deleted separately, so switching <b>Backup type</b> deletes neither.')
        . "</p></blockquote>\n";
    $html .= "<div id='{$select}Age'" . ($mode === 'gfs' ? " style='display: none;'" : '') . ">\n"
        . $row('deleteBackupsOlderThan', 'Deletes backup sets older than this many days, failed ones too. Leave it empty to keep every set.')
        . $row('keepMinBackups', 'The newest this-many good sets of each <b>Backup type</b> are never deleted by <b>Delete backups if older than x days</b>, however old they get. Failed or unfinished sets don\'t count.')
        . "</div>\n<div id='{$select}Gfs'" . ($mode === 'gfs' ? '' : " style='display: none;'") . ">\n"
        . $row('keepDaily', 'Keeps the newest good set of each of the last this-many days that have one, so a day without one doesn\'t count. A set any of these four keeps stays; the rest, failed or unfinished ones too, are deleted. Leave all four empty to keep every set.')
        . $row('keepWeekly', 'The newest good set of each of the last this-many weeks (Monday to Sunday) that have one.')
        . $row('keepMonthly', 'The newest good set of each of the last this-many months that have one.')
        . $row('keepYearly', 'The newest good set of each of the last this-many years that have one.')
        . "</div>\n";
    return $html;
}
