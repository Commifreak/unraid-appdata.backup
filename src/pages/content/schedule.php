<?php
// Included by settings.php inside its form: the extra schedule (ABSettings::forSchedule('extra'))

$extraGroups     = array_keys($abSettings->getContainerGroups());
$extraContainers = array_column((new DockerClient())->getDockerContainers() ?: [], 'Name');
natcasesort($extraContainers);
$weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$ordinal  = fn($day) => $day . (in_array($day, [11, 12, 13]) ? 'th' : (['th', 'st', 'nd', 'rd'][$day % 10] ?? 'th'));
?>
<dl>
    <dt><b>Extra schedule</b></dt>
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
    <p>A second schedule that backs up only the containers chosen below, for example daily for apps whose data changes every day. It writes to its own destination with its own retention and skips the flash drive, VM meta and extra files. Scripts run as usual.</p>
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
    <dt><b>Extra schedule containers</b></dt>
    <dd><select id="extraContainers" name="extraContainers[]" multiple size="8">
<?php foreach ($extraGroups as $group): ?>
            <option value="<?= htmlspecialchars('__grp__' . $group) ?>"<?= in_array('__grp__' . $group, $abSettings->extraContainers, true) ? ' selected' : '' ?>>Group: <?= htmlspecialchars($group) ?></option>
<?php endforeach; ?>
<?php foreach ($extraContainers as $name): ?>
            <option value="<?= htmlspecialchars($name) ?>"<?= in_array($name, $abSettings->extraContainers, true) ? ' selected' : '' ?>><?= htmlspecialchars($name) ?></option>
<?php endforeach; ?>
        </select>
    </dd>
</dl>
<blockquote class='inline_help'>
    <p>Ctrl or Cmd + click to pick several. A group backs up all its containers, stopped and started as one unit. Each container's own settings apply, so a container set to skip is skipped here too.</p>
</blockquote>

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
