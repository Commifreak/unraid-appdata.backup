<?php

use unraid\plugins\AppdataBackup\ABHelper;
use unraid\plugins\AppdataBackup\ABSettings;

/** @var $abSettings ABSettings set by settings.php, which also saves this form (ABSettings::storeForm) */

$extraAll = (new DockerClient())->getDockerContainers() ?: [];
$extraIcon = fn($container) => empty($container['Icon']) ? '/plugins/dynamix.docker.manager/images/question.png' : $container['Icon'];
// A select for a run-wide setting: '' = the Settings tab's value, named in the first option (see ABSettings::EXTRA_SAME_AS)
$sameAs = function (string $name, string $label, array $options, string $mainKey, string $help) use ($abSettings) {
    $main = $options[(string)$abSettings->$mainKey] ?? (string)$abSettings->$mainKey;
    $html = "<dl>\n    <dt><b>$label</b></dt>\n    <dd><select id='$name' name='$name' data-setting='" . htmlspecialchars((string)$abSettings->$name) . "'>\n"
        . "        <option value=''>Same as Settings tab (" . htmlspecialchars($main) . ")</option>\n";
    foreach ($options as $value => $text) {
        $html .= "        <option value='" . htmlspecialchars((string)$value) . "'>" . htmlspecialchars($text) . "</option>\n";
    }
    return $html . "    </select></dd>\n</dl>\n<blockquote class='inline_help'><p>$help</p></blockquote>\n";
};
$yesNo = ['yes' => 'Yes', 'no' => 'No'];
$cores = ['0' => 'All cores'];
for ($i = 1; $i < (int)trim((string)shell_exec('nproc')); $i++) {
    $cores[(string)$i] = (string)$i;
}
$weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$ordinal  = fn($day) => $day . (in_array($day, [11, 12, 13]) ? 'th' : (['th', 'st', 'nd', 'rd'][$day % 10] ?? 'th'));
?>
<?php include_once __DIR__ . '/head.php'; ?>

<div class="title"><span class="left"><i class="fa fa-clock-o title"></i>Extra schedule</span></div>
<?php if (($abExtraSaved ?? null) === false): ?>
<p class="ab-warn">Not saved: the existing settings could not be read, and saving only this tab would have wiped them.</p>
<?php endif; ?>

<form id="abExtraForm" method="post">
<input type="hidden" name="csrf_token" value="<?= _var($var, 'csrf_token') ?>"/>
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

<div class="title"><span class="left"><i class="fa fa-cog title"></i>Backup options</span></div>
<?= $sameAs('extraBackupMethod', 'Backup type', ['stopAll' => 'Stop all', 'oneAfterTheOther' => 'For each container'], 'backupMethod', 'How this schedule stops its containers; see <b>Backup type</b> on the Settings tab.') ?>
<?= $sameAs('extraSnapshotMode', 'Use snapshots', ['no' => 'No', 'yes' => 'Yes, on ZFS and btrfs'], 'snapshotMode', 'Snapshots for this schedule; see <b>Use snapshots</b> on the Settings tab.') ?>
<?= $sameAs('extraCompression', 'Use Compression?', ['no' => 'No', 'yes' => 'Yes, normal', 'yesMulticore' => 'Yes, multicore'], 'compression', 'Compression for this schedule\'s archives.') ?>
<?= $sameAs('extraCompressionCpuLimit', 'How many cores should be used?', $cores, 'compressionCpuLimit', 'Only used with <b>Yes, multicore</b>.') ?>
<?= $sameAs('extraFlashBackup', 'Backup the flash drive?', $yesNo, 'flashBackup', 'No by default, so a frequent run doesn\'t repeat the flash zip the main schedule makes.') ?>
<dl>
    <dt><b>Copy the flash backup to a custom destination</b></dt>
    <dd><input style="width: 500px;" type='text' class='ftAttach' id="extraFlashBackupCopy" name="extraFlashBackupCopy"
               value="<?= htmlspecialchars($abSettings->extraFlashBackupCopy) ?>" data-pickroot="/mnt/" data-pickfolders/></dd>
</dl>
<blockquote class='inline_help'><p>Only used when this schedule's <b>Backup the flash drive?</b> is Yes. Leave empty to skip the copy.</p></blockquote>
<?= $sameAs('extraBackupVMMeta', 'Backup VM meta?', $yesNo, 'backupVMMeta', 'No by default, like the flash backup.') ?>

<div class="title"><span class="left"><i class="fa fa-bell title"></i>Notifications</span></div>
<?= $sameAs('extraNotification', 'Notification Settings:', [ABHelper::LOGLEVEL_ERR => 'Errors only', ABHelper::LOGLEVEL_WARN => 'Warnings and errors', 'disabled' => 'Disabled'], 'notification', 'Which problems in this schedule\'s runs send a notification.') ?>
<?= $sameAs('extraSuccessLogWanted', 'Create success notification:', ['no' => 'No', 'yes' => 'Yes'], 'successLogWanted', 'A notification after each successful run of this schedule.') ?>
<?= $sameAs('extraUpdateLogWanted', 'Send notification if containers were updated:', ['no' => 'No', 'yes' => 'Yes'], 'updateLogWanted', 'A notification when this schedule\'s run updated containers.') ?>

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

<div class="title"><span class="left"><i class="fa fa-i-cursor title"></i>Custom scripts</span></div>
<dl>
    <dt><b>Scripts</b></dt>
    <dd><select id="extraScripts" name="extraScripts" data-setting="<?= htmlspecialchars($abSettings->extraScripts) ?>" onchange="abExtraToggle();">
            <option value="">Same as Settings tab</option>
            <option value="own">Own scripts</option>
        </select></dd>
</dl>
<blockquote class='inline_help'><p><b>Same as Settings tab</b> runs the Settings tab's scripts in this schedule's runs too. <b>Own scripts</b> uses only the fields below; an empty one runs nothing. Scripts must return 0 for success, or 2 (pre-container) to skip that container, and can't live on <code>/boot</code>.</p></blockquote>
<div id="extraScriptsOwn">
<dl>
    <dt>Pre-run script</dt>
    <dd><input style="width: 500px;" type='text' class='ftAttach' id="extraPreRunScript" name="extraPreRunScript"
               value="<?= htmlspecialchars($abSettings->extraPreRunScript) ?>" data-pickroot="/mnt/"/></dd>
</dl>
<blockquote class='inline_help'><p>Runs BEFORE ANYTHING is done. Sent arguments: <code>pre-run</code>, <code>destination path</code></p></blockquote>
<dl>
    <dt>Pre-backup script</dt>
    <dd><input style="width: 500px;" type='text' class='ftAttach' id="extraPreBackupScript" name="extraPreBackupScript"
               value="<?= htmlspecialchars($abSettings->extraPreBackupScript) ?>" data-pickroot="/mnt/"/></dd>
</dl>
<blockquote class='inline_help'><p>Runs BEFORE the backup starts. Sent arguments: <code>pre-backup</code>, <code>destination path</code></p></blockquote>
<dl>
    <dt>Pre-container-backup script</dt>
    <dd><input style="width: 500px;" type='text' class='ftAttach' id="extraPreContainerBackupScript" name="extraPreContainerBackupScript"
               value="<?= htmlspecialchars($abSettings->extraPreContainerBackupScript) ?>" data-pickroot="/mnt/"/></dd>
</dl>
<blockquote class='inline_help'><p>Runs before each container's backup; exit code 2 skips it. Sent arguments: <code>pre-container</code>, <code>container name</code></p></blockquote>
<dl>
    <dt>Post-container-backup script</dt>
    <dd><input style="width: 500px;" type='text' class='ftAttach' id="extraPostContainerBackupScript" name="extraPostContainerBackupScript"
               value="<?= htmlspecialchars($abSettings->extraPostContainerBackupScript) ?>" data-pickroot="/mnt/"/></dd>
</dl>
<blockquote class='inline_help'><p>Runs after each container's backup. Sent arguments: <code>post-container</code>, <code>container name</code></p></blockquote>
<dl>
    <dt>Post-backup script</dt>
    <dd><input style="width: 500px;" type='text' class='ftAttach' id="extraPostBackupScript" name="extraPostBackupScript"
               value="<?= htmlspecialchars($abSettings->extraPostBackupScript) ?>" data-pickroot="/mnt/"/></dd>
</dl>
<blockquote class='inline_help'><p>Runs AFTER the backup. Sent arguments: <code>post-backup</code>, <code>destination path</code></p></blockquote>
<dl>
    <dt>Post-run script</dt>
    <dd><input style="width: 500px;" type='text' class='ftAttach' id="extraPostRunScript" name="extraPostRunScript"
               value="<?= htmlspecialchars($abSettings->extraPostRunScript) ?>" data-pickroot="/mnt/"/></dd>
</dl>
<blockquote class='inline_help'><p>Runs at the very end. Sent arguments: <code>post-run</code>, <code>destination path</code>, <code>true/false</code> (success)</p></blockquote>
</div>

<div class="title"><span class="left"><i class="fa fa-plus-square title"></i>Some extra options</span></div>
<dl>
    <dt>Include extra files/folders</dt>
    <dd><div style="display: table; width: 300px;"><textarea id="extraIncludeFiles" name="extraIncludeFiles" onfocus="$(this).next('.ft').slideDown('fast');" style="resize: vertical; width: 400px;"><?= htmlspecialchars(implode("\r\n", $abSettings->extraIncludeFiles)) ?></textarea><div class="ft" style="display: none;"><div class="fileTreeDiv"></div><button onclick="addSelectionToList(this);  return false;">Add to list</button></div></div></dd>
</dl>
<blockquote class='inline_help'><p>Files or folders this schedule also backs up, into an <code>extra_files</code> archive in its set. Empty by default: the main schedule's list isn't used here.</p></blockquote>
<dl>
    <dt>Global exclusion list</dt>
    <dd><select id="extraGlobalExclusionsOwn" name="extraGlobalExclusionsOwn" data-setting="<?= htmlspecialchars($abSettings->extraGlobalExclusionsOwn) ?>" onchange="abExtraToggle();">
            <option value="">Same as Settings tab</option>
            <option value="yes">Own list</option>
        </select>
        <div id="extraGlobalExclusionsBox" style="display: table; width: 300px;"><textarea id="extraGlobalExclusions" name="extraGlobalExclusions" style="resize: vertical; width: 400px;"><?= htmlspecialchars(implode("\r\n", $abSettings->extraGlobalExclusions)) ?></textarea></div></dd>
</dl>
<blockquote class='inline_help'><p><b>Same as Settings tab</b> uses its global exclusion list. <b>Own list</b> uses only these lines, one pattern per line, such as <code>*.log</code> or <code>logs</code>.</p></blockquote>

<dl>
    <dt>Done?</dt>
    <dd><span><input type="submit" value="Save"/> <input type="reset" value="Discard"/>
        <button id="extraBackup" class="ab-job" style="margin-left: 15px;" title="Backs up the extra schedule's containers now, with the saved settings, so save any changes first."<?= empty($abSettings->extraDestination) || empty($abSettings->extraContainers) ? ' data-blocked="Save a destination and containers first"' : '' ?>>Run extra backup</button></span>
    </dd>
</dl>
</form>

<script>
    /** Shows the script fields and the exclusion list only when this schedule uses its own */
    function abExtraToggle() {
        $('#extraScriptsOwn').toggle($('#extraScripts').val() === 'own');
        $('#extraGlobalExclusionsBox').toggle($('#extraGlobalExclusionsOwn').val() === 'yes');
    }

    $(function () {
        checkBackupFrequency('extraFrequency'); // settings.php
        abExtraToggle();

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
