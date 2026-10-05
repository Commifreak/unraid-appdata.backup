<?php

use unraid\plugins\AppdataBackup\ABHelper;
use unraid\plugins\AppdataBackup\ABSettings;

/**
 * One container's row and settings panel, for the Settings tab or (with $extra) the Extra schedule tab, which saves
 * extraContainerSettings where '' means "Same as Settings tab" (see ABSettings::forSchedule)
 */
function abContainerPanel(array $container, ABSettings $abSettings, array $containerHelp, bool $extra = false) {
    $field         = $extra ? 'extraContainerSettings' : 'containerSettings';
    $idPrefix      = $extra ? 'extra_' : '';
    $useStandard   = 'Use standard'; // on the extra tab: its Container defaults (ABSettings::EXTRA_DEFAULTS)
    $inheritOption = $extra ? "<option value=''>Same as Settings tab</option>\n" : '';
    $volumeTarget  = $extra ? 'data-exclude="extra_' . $container['Name'] . '_exclude"' : 'data-container="' . $container['Name'] . '"';
    $main          = $abSettings->getContainerSpecificSettings($container['Name'], false);
    if ($extra) {
        $own              = $abSettings->extraContainerSettings[$container['Name']] ?? [];
        $containerSetting = ['group' => $main['group']];
        foreach ([...ABSettings::EXTRA_CONTAINER_KEYS, 'excludeOwn'] as $key) {
            $containerSetting[$key] = htmlspecialchars((string)($own[$key] ?? ''));
        }
        $ownExcludes                 = (array)($own['exclude'] ?? []);
        $containerSetting['exclude'] = $containerSetting['excludeOwn'] === 'yes' ? $ownExcludes : $main['exclude'];
        $containerExcludes           = htmlspecialchars(implode("\r\n", $ownExcludes));
        $included                    = in_array($container['Name'], $abSettings->extraContainers, true);
    } else {
        $containerSetting     = $main;
        $realContainerSetting = htmlspecialchars(print_r($abSettings->getContainerSpecificSettings($container['Name']), true));
        $containerExcludes    = htmlspecialchars(implode("\r\n", $containerSetting['exclude']));
    }

    $isPlex = str_contains(strtolower($container['Name']), 'plex');

    $plexHint                = '';
    $plexContainerNameSuffix = '';
    if ($isPlex) {
        $plexContainerNameSuffix = ' - Plex detected! Open for more...';
        $plexHint                = <<<HTML
<dt><b>PLEX detected!</b></dt>
<dd><div style="display: table; font-weight: bold;">This container seems to be a plex container.<br />Please consider setting some exclusions.<br /><a href="https://forums.unraid.net/topic/137710-plugin-appdatabackup/?do=findComment&comment=1250363" target="_blank">Click here</a> and scroll to "Hints" for a suggestion.</div></dd>
HTML;

    }

    $image   = empty($container['Icon']) ? '/plugins/dynamix.docker.manager/images/question.png' : $container['Icon'];
    $volumes = ABHelper::getContainerVolumes($container, true);

    if (empty($volumes)) {
        $volumes = "<b>No volumes - container will NOT be backed up!</b>";
    } else {
        foreach ($volumes as $index => $volume) {
            $excluded        = in_array($volume, $containerSetting['exclude']) ? ' - <abbr style="color: red; font-weight: bold;" title="Will not be backed up! See exclusions list below!">EXCLUDED!</abbr> ' : false;
            $internalVolume  = ABHelper::isVolumeWithinAppdata($volume);
            $volumes[$index] = '<span class="fa ' . (!$internalVolume ? 'fa-external-link' : 'fa-folder') . '"></span> <code style="cursor:pointer;" ' . $volumeTarget . ' data-internal="' . ($internalVolume ? 'true' : 'false') . '" data-excluded="' . ($excluded ? 'true' : 'false') . '" onclick="addVolumeToExclude(this);">' . htmlspecialchars($volume) . '</code>' . $excluded . '<span style="display: none;" class="multiVolumeWarn"> - <a target="_blank" href="https://forums.unraid.net/topic/137710-plugin-appdatabackup/?do=findComment&comment=1250363">used in multiple containers!</a></span>';
        }
        $volumes = implode('<br />', $volumes);
    }


    if ($extra) {
        $actualSettingsDiv = '';
        $multiMappingSpan  = '';
        $rowControl        = "        <dd><label for=\"extraInclude_{$container['Name']}\">Include?</label>\n        <select name=\"extraContainers[{$container['Name']}]\" id=\"extraInclude_{$container['Name']}\">\n            <option value=\"no\">No</option>\n            <option value=\"yes\"" . ($included ? ' selected' : '') . ">Yes</option>\n    </select>\n    </dd>";
        $groupControl      = '<dd><span>' . ($containerSetting['group'] === '' ? 'None' : htmlspecialchars($containerSetting['group'])) . ' (set on the Settings tab)</span></dd>';
        $settingsExcludes  = htmlspecialchars(implode("\n", $main['exclude']) ?: 'none');
        $readonly          = $containerSetting['excludeOwn'] === 'yes' ? '' : ' readonly'; // editable only as an Own list (extra.php)
        $excludeControl    = <<<HTML
<dd><div style="display: table; width: 300px;"><select id='extra_{$container['Name']}_excludeOwn' name="extraContainerSettings[{$container['Name']}][excludeOwn]" data-setting="{$containerSetting['excludeOwn']}"><option value=''>Same as Settings tab</option><option value='yes'>Own list</option></select><textarea id="extra_{$container['Name']}_exclude" name="extraContainerSettings[{$container['Name']}][exclude]"$readonly placeholder="Settings tab: {$settingsExcludes}" onfocus="$(this).next('.ft').slideDown('fast');" style="resize: vertical; width: 400px;">$containerExcludes</textarea><div class="ft" style="display: none;"><div class="fileTreeDiv"></div><button onclick="addSelectionToList(this);  return false;">Add to list</button></div></div></dd>
HTML;
    } else {
        $actualSettingsDiv = "<div style=\"display: none\" id=\"actualContainerSettings_{$container['Name']}\">$realContainerSetting</div>";
        $multiMappingSpan  = " <span id=\"containerMultiMappingIssue_{$container['Name']}\" style=\"display: none; color: darkorange;\">WARN: Multi mapping detected!</span>";
        $rowControl        = "        <dd><label for=\"{$container['Name']}_skip\">Skip?</label>\n        <select name=\"containerSettings[{$container['Name']}][skip]\" id=\"{$container['Name']}_skip\" data-setting=\"{$containerSetting['skip']}\">\n            <option value=\"no\">No</option>\n            <option value=\"yes\">Yes</option>\n    </select>\n    </dd>";
        $groupControl      = <<<HTML
<dd><div style="display: table"><input list="containerGroups" type="text" placeholder="None - Double click for a list" id='{$idPrefix}{$container['Name']}_group' name="{$field}[{$container['Name']}][group]" value="{$containerSetting['group']}" onkeyup="$(this).next().show();" onchange="$(this).next().show();" autocomplete="off" /><span style="color: red; display: none;"><br />To adjust group order, save your changes.</span></div></dd>
HTML;
        $excludeControl    = <<<HTML
<dd><div style="display: table; width: 300px;"><textarea id="{$idPrefix}{$container['Name']}_exclude" name="{$field}[{$container['Name']}][exclude]" onfocus="$(this).next('.ft').slideDown('fast');" style="resize: vertical; width: 400px;">$containerExcludes</textarea><div class="ft" style="display: none;"><div class="fileTreeDiv"></div><button onclick="addSelectionToList(this);  return false;">Add to list</button></div></div></dd>
HTML;
    }

    echo <<<HTML
$actualSettingsDiv
        <dl class="ab-container-row">
        <dt class="containerSettingsDt"><img alt="pic" src='$image' height='16' /> <i title='{$container['Image']}' class='fa fa-info-circle'></i> <abbr title='Click for advanced settings'>{$container['Name']}$plexContainerNameSuffix</abbr>$multiMappingSpan</dt>
$rowControl
        </dl>

<blockquote class='inline_help ab-box'>
<dl>
$plexHint
<dt>Configured volumes</dt>
<dd><div style="display: table">$volumes</div></dd>
</dl>
<blockquote class='inline_help'><p>{$containerHelp['volumes']}</p></blockquote>
<dl>
<dt>Member of group</dt>
$groupControl
</dl>
<blockquote class='inline_help'><p>{$containerHelp['group']}</p></blockquote>
<dl>
<dt>Save external volumes?</dt>
<dd><select id='{$idPrefix}{$container['Name']}_backupExtVolumes' name="{$field}[{$container['Name']}][backupExtVolumes]" data-setting="{$containerSetting['backupExtVolumes']}" >
{$inheritOption}		<option value='no'>No</option>
		<option value='yes'>Yes</option>
	</select></dd>
</dl>
<blockquote class='inline_help'><p>{$containerHelp['extVolumes']}</p></blockquote>
<dl>
<dt>Update container after backup?</dt>
<dd><select id='{$idPrefix}{$container['Name']}_updateContainer' name="{$field}[{$container['Name']}][updateContainer]" data-setting="{$containerSetting['updateContainer']}">
            <option value=''>$useStandard</option>
            <option value='yes'>Yes</option>
            <option value='no'>No</option>
        </select>
    </dd>
</dl>
<blockquote class='inline_help'><p>{$containerHelp['update']}</p></blockquote>
<dl>
<dt>Excluded folders/files</dt>
$excludeControl
</dl>
<blockquote class='inline_help'><p>{$containerHelp['exclude']}</p></blockquote>
<div class="ab-advanced-toggle" onclick="$(this).next().toggle();"><a>Show advanced options</a></div>
<div style="display: none;">
<dl>
<dt>Skip backup?</dt>
<dd><select id='{$idPrefix}{$container['Name']}_skipBackup' name="{$field}[{$container['Name']}][skipBackup]" data-setting="{$containerSetting['skipBackup']}" >
{$inheritOption}		<option value='no'>No, do backup as well</option>
		<option value='yes'>Yes, skip backup and do stop/start only</option>
	</select></dd>
</dl>
<blockquote class='inline_help'><p>{$containerHelp['skipBackup']}</p></blockquote>
<dl>
<dt>Verify Backup?</dt>
<dd><select id='{$idPrefix}{$container['Name']}_verifyBackup' name="{$field}[{$container['Name']}][verifyBackup]" data-setting="{$containerSetting['verifyBackup']}" >
		<option value=''>$useStandard</option>
		<option value='yes'>Yes</option>
		<option value='no'>No</option>
	</select></dd>
</dl>
<blockquote class='inline_help'><p>{$containerHelp['verify']}</p></blockquote>
<dl>
<dt>Ignore errors during backup?</dt>
<dd>
    <select id='{$idPrefix}{$container['Name']}_ignoreBackupErrors' name="{$field}[{$container['Name']}][ignoreBackupErrors]" data-setting="{$containerSetting['ignoreBackupErrors']}">
        <option value=''>$useStandard</option>
        <option value='yes'>Yes</option>
		<option value='no'>No</option>
	</select>
</dd>
</dl>
<blockquote class='inline_help'><p>{$containerHelp['ignoreErrors']}</p></blockquote>
<dl>
<dt>Skip stopping of container?</dt>
<dd><select id='{$idPrefix}{$container['Name']}_dontStop' name="{$field}[{$container['Name']}][dontStop]" data-setting="{$containerSetting['dontStop']}" >
            <option value=''>$useStandard</option>
            <option value='no'>No</option>
            <option value='yes'>Yes</option>
        </select></dd>
</dl>
<blockquote class='inline_help'><p>{$containerHelp['dontStop']}</p></blockquote>
</div>
</blockquote>
HTML;
}
