<?php

use unraid\plugins\AppdataBackup\ABHelper;
use unraid\plugins\AppdataBackup\ABSettings;

/** @var $abSettings ABSettings set by settings.php, which also saves this form (ABSettings::storeForm) */

$extraAll = (new DockerClient())->getDockerContainers() ?: [];
$extraIcon = fn($container) => empty($container['Icon']) ? '/plugins/dynamix.docker.manager/images/question.png' : $container['Icon'];
$weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$ordinal  = fn($day) => $day . (in_array($day, [11, 12, 13]) ? 'th' : (['th', 'st', 'nd', 'rd'][$day % 10] ?? 'th'));
?>
<?php include_once __DIR__ . '/head.php'; ?>

<div class="title"><span class="left"><i class="fa fa-clock-o title"></i>Extra schedule</span></div>
<?php if (($abExtraSaved ?? null) === false): ?>
<p class="ab-warn">Not saved: the existing settings could not be read, and saving only this tab would have wiped them.</p>
<?php endif; ?>

<form id="abExtraForm" method="post">
<input type="hidden" name="extraScheduleForm" value="1"/>
<dl>
    <dt><b>Extra schedule frequency</b></dt>
    <dd><select id='extraFrequency' name="extraFrequency" onchange="checkBackupFrequency('extraFrequency');"
                data-setting="<?= htmlspecialchars((string)$abSettings->extraFrequency) ?>">
            <option value='disabled'>Disabled</option>
            <option value='daily'>Daily</option>
            <option value='weekly'>Weekly</option>
            <option value='monthly'>Monthly</option>
            <option value='custom'>Custom</option>
        </select>
    </dd>
</dl>
<blockquote class='inline_help'>
    <p>Backs up only the containers chosen below, for example daily for apps whose data changes every day, into its own destination with its own retention. It skips the flash drive, VM meta and extra files; scripts run as usual. <b>Disabled</b> runs it only from <b>Run extra backup</b>.</p>
    <p>A scheduled run that starts while another job runs waits for it, up to 12 hours.</p>
</blockquote>

<dl>
    <dt><b>Day of Week:</b></dt>
    <dd><select id='extraFrequencyDay' name="extraFrequencyWeekday" data-setting="<?= htmlspecialchars((string)$abSettings->extraFrequencyWeekday) ?>">
<?php foreach ($weekdays as $day => $name): ?>
            <option value='<?= $day ?>'><?= $name ?></option>
<?php endforeach; ?>
        </select>
    </dd>

    <dt><b>Day of Month:</b></dt>
    <dd><select id='extraFrequencyDayOfMonth' name="extraFrequencyDayOfMonth" data-setting="<?= htmlspecialchars((string)$abSettings->extraFrequencyDayOfMonth) ?>">
<?php for ($day = 1; $day <= 31; $day++): ?>
            <option value='<?= $day ?>'><?= $ordinal($day) ?></option>
<?php endfor; ?>
        </select>
    </dd>

    <dt><b>Hour:</b></dt>
    <dd><input type="number" min="00" max="23" id='extraFrequencyHour' name="extraFrequencyHour"
               value="<?= htmlspecialchars((string)$abSettings->extraFrequencyHour) ?>"/></dd>

    <dt><b>Minute:</b></dt>
    <dd><input type="number" min="00" max="59" id='extraFrequencyMinute' name="extraFrequencyMinute"
               value="<?= htmlspecialchars((string)$abSettings->extraFrequencyMinute) ?>"/></dd>

    <dt><b>Custom Cron Entry:</b></dt>
    <dd><input type='text' id='extraFrequencyCustom' name="extraFrequencyCustom"
               value="<?= htmlspecialchars($abSettings->extraFrequencyCustom) ?>"
               placeholder="Setting this will disable the other options"/></dd>
</dl>

<dl>
    <dt><b>Extra schedule destination</b></dt>
    <dd><input type='text' class='ftAttach' id="extraDestination" name="extraDestination"
               value="<?= htmlspecialchars($abSettings->extraDestination) ?>"
               data-pickfilter="HIDE_FILES_FILTER" data-pickfolders="true"></dd>
</dl>
<blockquote class='inline_help'>
    <p>Must differ from <b>Backup destination</b>, so each folder's retention only sees its own sets. A subfolder of it is fine. To restore, pick this folder as the source on the Restore tab.</p>
</blockquote>

<dl>
    <dt><b>Extra schedule: delete backups if older than x days:</b></dt>
    <dd><input id='extraDeleteBackupsOlderThan' name="extraDeleteBackupsOlderThan" type='number'
               value='<?= htmlspecialchars((string)$abSettings->extraDeleteBackupsOlderThan) ?>'
               placeholder='Leave empty to disable'/></dd>
</dl>
<blockquote class='inline_help'>
    <p>Works like <b>Delete backups if older than x days</b>, for the extra destination only.</p>
</blockquote>

<dl>
    <dt><b>Extra schedule: keep at least this many backups:</b></dt>
    <dd><input id='extraKeepMinBackups' name="extraKeepMinBackups" type='number' value='<?= htmlspecialchars((string)$abSettings->extraKeepMinBackups) ?>'
               placeholder='Leave empty to disable'/></dd>
</dl>
<blockquote class='inline_help'>
    <p>Works like <b>Keep at least this many backups</b>, for the extra destination only.</p>
</blockquote>

<!-- Mirrors the Docker section of settings.php, with the same classes -->
<div class="ab-docker-cols">
    <div>
        <div class="title"><span class="left"><i class="fa fa-docker title"></i>Containers in the extra schedule. <b>Click on container name to open</b></span></div>
<?php
require_once __DIR__ . '/container.php';
// $containerHelp comes from settings.php; each '' option here means the container's value on the Settings tab
$extraHelp = $containerHelp;
foreach (['extVolumes', 'update', 'skipBackup', 'verify', 'ignoreErrors', 'dontStop'] as $key) {
    $extraHelp[$key] = preg_replace('/ ?<b>Use standard<\/b> follows.*$/s', '', $containerHelp[$key]) . ' <b>Same as Settings tab</b> uses this container\'s setting on the Settings tab.';
}
$extraHelp['group']   = 'Groups are set on the Settings tab: a group\'s containers are stopped, backed up and started as one unit.';
$extraHelp['exclude'] = '<b>Same as Settings tab</b> uses this container\'s exclusions there (shown greyed out). <b>Own list</b> uses only the lines here, so an empty own list excludes nothing. ' . $containerHelp['exclude'];
foreach ($extraAll as $container) {
    abContainerPanel($container, $abSettings, $extraHelp, true);
}
?>
    </div>
    <div class="ab-start-order">
        <div class="title"><span class="left"><i class="fa fa-sort title"></i>Start order</span></div>
        <p>The extra schedule's own start sequence; containers are stopped in reverse order. A group keeps its order from the Settings tab.</p>
        <input type="hidden" id="extraContainerOrder" name="extraContainerOrder"/>
        <ul class="sortable" id="extraOrderSortable">
<?php foreach (ABHelper::sortContainers($extraAll, $abSettings->extraContainerOrder ?: $abSettings->containerOrder, false, false) as $container): ?>
<?php $id = $container['isGroup'] ? '__grp__' . $container['Name'] : $container['Name']; ?>
            <li id="extraContainerOrder_<?= htmlspecialchars($id) ?>"><span class="ab-drag"><i class="fa fa-sort"></i> <?= $container['isGroup'] ? '<i class="fa fa-folder" style="padding-right: 10px;"></i>' : '<img src="' . htmlspecialchars($extraIcon($container)) . '" height="16" />' ?> <?= htmlspecialchars($container['Name']) ?></span></li>
<?php endforeach; ?>
        </ul>
    </div>
</div>

<dl>
    <dt>Done?</dt>
    <dd><span><input type="submit" value="Save"/> <input type="reset" value="Discard"/>
        <button id="extraBackup" class="ab-job" style="margin-left: 15px;" title="Backs up the extra schedule's containers now, with the saved settings, so save any changes first."<?= empty($abSettings->extraDestination) || empty($abSettings->extraContainers) ? ' data-blocked="Save a destination and containers first"' : '' ?>>Run extra backup</button></span>
    </dd>
</dl>
</form>

<script>
    $(function () {
        checkBackupFrequency('extraFrequency'); // settings.php

        // As settings.php sends containerOrder; storeForm() parses it
        $('#abExtraForm').on('submit', function () {
            $('#extraContainerOrder').val($('#extraOrderSortable').sortable('serialize', {expression: /(.+?)_(.+)/}));
        });

        $('#extraBackup').on('click', function () {
            swal({
                title: "Proceed?",
                text: "Back up the extra schedule's containers now?",
                type: 'warning',
                showCancelButton: true,
                confirmButtonText: "Yep",
                cancelButtonText: "Nah"
            }, function () {
                abStartJob({action: 'extraBackup'}); // log.php
            });
            return false;
        });
    });
</script>
