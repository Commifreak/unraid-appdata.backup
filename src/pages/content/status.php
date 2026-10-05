<?php

use unraid\plugins\AppdataBackup\ABHelper;
use unraid\plugins\AppdataBackup\ABSettings;
use unraid\plugins\AppdataBackup\ABStatus;

// Included by log.php, and by http.php's getStatus when a run ends with the page open
require_once dirname(__DIR__, 2) . '/include/ABStatus.php';

$abSettings = $abSettings ?? new ABSettings();
$summary    = ABStatus::summary($abSettings);
$date       = fn($set) => $set['date']->format('d.m.Y H:i');
$rows       = [];
$tips       = [
    'Free space' =>'Free space where backups are written. Under /mnt/user, Unraid adds up every disk the share can use; each archive still has to fit on one disk.',
];

if ($summary['latest']) {
    $state = ['ok' => 'OK', 'failed' => 'failed', 'incomplete' => 'incomplete'][$summary['latest']['state']];
    $rows['Last backup'] = $date($summary['latest']) . ' &middot; ' . $state . ($summary['duration'] !== null ? ' &middot; took ' . ABStatus::minutes($summary['duration']) : '');
} else {
    $rows['Last backup'] = $summary['recorded'] ? 'None yet' : 'Shown after the next backup run';
}
if ($summary['recorded']) {
    $rows['Backup sets'] = count($summary['ok']) . ' good (' . ABHelper::bytes($summary['okSize']) . ')'
        . ($summary['failed'] ? ', ' . count($summary['failed']) . ' failed (' . ABHelper::bytes($summary['failedSize']) . ')' : '')
        . ($summary['ok'] ? ' &middot; newest ' . $date($summary['ok'][0]) . ', oldest ' . $date($summary['ok'][count($summary['ok']) - 1]) : '')
        . ' &middot; as of the last backup run';
}
$rows['Next scheduled run'] = $summary['next'] ? $summary['next']->format('D d.m.Y H:i') : ($abSettings->backupFrequency === 'custom' ? 'Custom: ' . htmlspecialchars($abSettings->backupFrequencyCustom) : 'Not scheduled');
if ($abSettings->extraFrequency !== 'disabled') {
    $extraSummary = ABStatus::summary($abSettings->forSchedule('extra'));
    $rows['Extra schedule'] = ($extraSummary['next'] ? 'Next ' . $extraSummary['next']->format('D d.m.Y H:i') : 'Custom: ' . htmlspecialchars($abSettings->extraFrequencyCustom))
        . ' &middot; ' . ($extraSummary['recorded'] ? count($extraSummary['ok']) . ' good' . ($extraSummary['ok'] ? ', newest ' . $date($extraSummary['ok'][0]) : '') : 'sets shown after its first run');
}
$rows['Free space'] = $summary['free'] === false ? 'Unknown' : ABHelper::bytes($summary['free']) . ' free' . ($summary['ok'] ? ' &middot; newest backup ' . ABHelper::bytes($summary['ok'][0]['size']) : '');

$warnings = [];
if ($summary['stale'] !== false) {
    $warnings[] = $summary['stale'] === null ? 'No successful backup yet.' : 'No successful backup for ' . $summary['stale'] . ' days (schedule: ' . $abSettings->backupFrequency . ').';
}
if (isset($extraSummary) && $extraSummary['stale'] !== false) {
    $warnings[] = $extraSummary['stale'] === null ? 'No successful extra schedule backup yet.' : 'No successful extra schedule backup for ' . $extraSummary['stale'] . ' days (schedule: ' . $abSettings->extraFrequency . ').';
}
if ($summary['lowSpace']) {
    $warnings[] = 'Free space (' . ABHelper::bytes($summary['free']) . ') is less than the newest backup (' . ABHelper::bytes($summary['ok'][0]['size']) . ').';
}

?>
<div class="ab-status">
    <dl class="ab-grid">
<?php foreach ($rows as $label => $value): ?>
        <dt<?= isset($tips[$label]) ? ' title="' . htmlspecialchars($tips[$label]) . '"' : '' ?>><?= $label ?></dt><dd><?= $value ?></dd>
<?php endforeach; ?>
    </dl>
<?php foreach ($warnings as $warning): ?>
    <p class="ab-warn"><?= $warning ?></p>
<?php endforeach; ?>
</div>
