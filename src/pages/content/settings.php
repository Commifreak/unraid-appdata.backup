<?php
$beta = '';
if (str_contains($_SERVER['REQUEST_URI'], 'Beta')) {
    $beta = '.beta';
}

require_once("/usr/local/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php");
require_once("/usr/local/emhttp/plugins/appdata.backup$beta/include/ABSettings.php");
require_once("/usr/local/emhttp/plugins/appdata.backup$beta/include/ABHelper.php");

use unraid\plugins\AppdataBackup\ABHelper;
use unraid\plugins\AppdataBackup\ABSettings;

if (!ABHelper::isArrayOnline()) {
    echo "<h1>Oooopsie!</h1><p>The array is NOT online!</p>";
    return;
}


/**
 * POST Handling
 */
if ($_POST) {
    if (!file_exists(ABSettings::$pluginDir)) {
        mkdir(ABSettings::$pluginDir);
    }

    if (isset($_POST['migrateConfig'])) {
        $abSettings = null;
        if (file_exists(ABSettings::getConfigPath())) {
            $abSettings = json_decode(file_get_contents(ABSettings::getConfigPath()), true);
        }

        if (empty($abSettings)) {
            $abSettings = [];
        }
        $oldConfig = json_decode(file_get_contents('/boot/config/plugins/ca.backup2/BackupOptions.json'), true);

        if (!empty($oldConfig['destinationShare'])) {
            $abSettings['destination'] = $oldConfig['destinationShare'];
        }

        $abSettings['allowedSources'] = ['/mnt/user/appdata', '/mnt/cache/appdata'];

        if (!empty($oldConfig['source'])) {
            if (!in_array(rtrim($oldConfig['source'], '/'), $abSettings['allowedSources'])) {
                $abSettings['allowedSources'][] = rtrim($oldConfig['source'], '/');
            }
            $abSettings['allowedSources'] = implode("\r\n", $abSettings['allowedSources']); // Hackety hack! 😅
        }

        if (!empty($oldConfig['compression'])) {
            $abSettings['compression'] = $oldConfig['compression'] == 'yes' ? 'yes' : 'no';
        }
        if (!empty($oldConfig['verify'])) {
            $abSettings['defaults']['verifyBackup'] = $oldConfig['verify'] == 'yes' ? 'yes' : 'no';
        }
        if (!empty($oldConfig['usbDestination'])) {
            $abSettings['flashBackup'] = 'yes';
        } else {
            $abSettings['flashBackup'] = 'no';
        }
        if (!empty($oldConfig['xmlDestination'])) {
            $abSettings['backupVMMeta'] = 'yes';
        } else {
            $abSettings['backupVMMeta'] = 'no';
        }

        if (!empty($oldConfig['stopScript'])) {
            $abSettings['preRunScript'] = $oldConfig['stopScript']; // Yes, the postScript is executed as custom stop script at the beginning!
        }
        if (!empty($oldConfig['preStartScript'])) {
            $abSettings['postBackupScript'] = $oldConfig['preStartScript']; // preStart is right before docker starts.
        }
        if (!empty($oldConfig['startScript'])) {
            $abSettings['postRunScript'] = $oldConfig['startScript']; // start was executed after all actions are done
        }


        if (!empty($oldConfig['updateApps'])) {
            $abSettings['defaults']['updateContainer'] = $oldConfig['updateApps'] == 'yes' ? 'yes' : 'no';
        }
        if (!empty($oldConfig['deleteOldBackup'])) {
            $abSettings['deleteBackupsOlderThan'] = $oldConfig['deleteOldBackup'];
        }

        $abSettings['backupFrequency']           = $oldConfig['cronSetting'];
        $abSettings['backupFrequencyWeekday']    = $oldConfig['cronDay'];
        $abSettings['backupFrequencyDayOfMonth'] = $oldConfig['cronMonth'];
        $abSettings['backupFrequencyHour']       = $oldConfig['cronHour'];
        $abSettings['backupFrequencyMinute']     = $oldConfig['cronMinute'];
        $abSettings['backupFrequencyCustom']     = $oldConfig['cronCustom'];

        if (!empty($oldConfig['dontStop'])) {
            foreach ($oldConfig['dontStop'] as $container => $true) {
                $abSettings['containerSettings'][$container]['skip'] = 'yes';
            }
        }

        ABSettings::store($abSettings);


        echo "<h1 style='color: green'>Settings were migrated!</h1><p>Please wait...</p><hr />";
        echo "<script>
window.setTimeout(function() {
    window.location=window.location.href;
}, 5000);
</script>";
        exit;
    }

    //Hack the order string
    parse_str($_POST['containerOrder'], $containerOrder);
    if (empty($containerOrder)) {
        $_POST['containerOrder'] = [];
    } else {
        $_POST = array_merge($_POST, $containerOrder);
    }

    if (!empty($_POST['containerGroupOrder'])) {
        foreach ($_POST['containerGroupOrder'] as $group => $order) {
            $containerOrder = null;
            parse_str($order, $containerOrder);
            if (empty($containerOrder)) {
                unset($_POST['containerGroupOrder'][$group]); # delete group from config
            } else {
                $_POST['containerGroupOrder'][$group] = $containerOrder['containerGroupOrder'][$group];
            }
        }
    }

    ABSettings::store($_POST);
}

$abSettings = new ABSettings();

if ($_POST) {
    list($code, $out) = $abSettings->checkCron();
}


/**
 * Please stop using global variables 🤐
 */
if (strstr('white,azure', $display['theme'])) {
    $bgcolor = '#f2f2f2';
} else {
    $bgcolor      = '#1c1c1c';
    $selectBorder = '1px solid #1c1b1b';
}

?>
<link type="text/css" rel="stylesheet" href="<?php autov('/webGui/styles/jquery.filetree.css') ?>">
<style>
    .fileTree {
        background: <?=$bgcolor?>;
        width: 300px;
        max-height: 150px;
        overflow-y: scroll;
        overflow-x: hidden;
        position: absolute;
        z-index: 100;
        display: none
    }

    blockquote select, blockquote textarea {
        color: black;
    }

    blockquote textarea:focus {
        background-color: unset;
    }

    <?php if(isset($selectBorder)): ?>
    blockquote select, blockquote textarea, blockquote input[type="text"] {
        border-bottom: <?= $selectBorder ?>;
        color: <?= $bgcolor ?>;
    }

    <?php endif; ?>

    .dockerSettings dt {
        width: 54%;
    }

    .sortable {
        list-style-type: none;
    }

    .sortable li {
        cursor: n-resize;
        margin: 0 15px 15px 15px;
    }

    .caBackupMigrationDiv {
        border: 1px solid red;
        border-radius: 25px;
        background: rgba(250, 33, 0, 0.3);
        padding: 0 10px 10px 10px;
    }

</style>

<div class="title"><span class="left"><i class="fa fa-hand-peace-o title"></i>Welcome to appdata backup</span></div>
<p>Welcome to the appdata backup plugin!</p>
<p>This plugin allows you to back up and restore all your appdata content! It takes care of everything (stop/start
    docker containers) including some extras (update docker containers)</p>
<p><b>For first time setup</b>, you need to know how this plugin is working! The main options are the <code>Appdata
        sources</code> and the <code>Backup destination</code>. The latter should be self explaining.<br/>The plugin
    does not simply copy the contents of <code>appdata</code> anymore (like the previous did). It reads all docker
    containers' mapped volumes. And this file/folder list will be the list we work with.</p>
<p>It also differs between internal and external volumes/mappings. And here the <code>Appdata sources</code> comes to
    play. Every volume mapping within those paths are considered "internal". Like "for the container to work"-internal
    (configs, logs etc.).<br/>Any mapping outside those paths are "external". Like storage or something (cloud, plex,
    ...).</p>
<p>In the default configuration, the plugin is just backing up any internal mapping and will skip external ones. You can
    adjust that for every container.</p>
<p>Please also read the help block for <b>Appdata sources</b> by clicking the title of the option!</p>

<div class="title"><span class="left"><i class="fa fa-cog title"></i>Main settings</span></div>

<?php
if (file_exists('/boot/config/plugins/ca.backup2/BackupOptions.json')) {
    echo <<<HTML
<div class="caBackupMigrationDiv">
<h3>Found configuration from old CA.Backup2 plugin!</h3>
<p>Do you want to migrate your existing settings herein? <small>This message will be displayed as long as the old config is found. Remove the plugin to get rid of this message.</small></p>
</div>
<form action="" method="post">
<input type="hidden" name="migrateConfig" value="" />
<button>Migrate settings</button>
</form>
<hr />
HTML;

}

if (($code ?? 0) != 0) {
    echo "<h1>Cron error!</h1><p>" . implode('; ', $out) . "</p>";
}
?>

<form id="abSettingsForm" method="post">
    <input type="hidden" name="csrf_token" value="<?= _var($var, 'csrf_token') ?>"/>
    <input type="hidden" name="settingsVersion" value="<?= ABSettings::$settingsVersion ?>"/>
    <dl>
        <dt><b>Backup type</b></dt>
        <dd><select id="backupMethod" name="backupMethod" data-setting="<?= $abSettings->backupMethod ?>">
                <option value="stopAll">Stop all containers, backup, start all</option>
                <option value="oneAfterTheOther">Stop, backup, start for each container</option>
            </select></dd>
    </dl>
    <blockquote class='inline_help'>
        <p>The plugin takes note of not started containers before backup and leaves them stopped afterwards.</p>
    </blockquote>

    <dl>

        <dt><b>Delete backups if older than x days:</b></dt>
        <dd><input id='deleteBackupsOlderThan' name="deleteBackupsOlderThan" type='number'
                   value='<?= $abSettings->deleteBackupsOlderThan ?>'
                   placeholder='Leave empty to disable'/></dd>

        <dt><b>Keep at least this many backups:</b></dt>
        <dd><input id='keepMinBackups' name="keepMinBackups" type='number' value='<?= $abSettings->keepMinBackups ?>'
                   placeholder='Leave empty to disable'/></dd>


        <dt><b>Appdata source(s)</b> Please note the infos inside help block!</dt>
        <dd>
            <div style="display: table; width: 300px;"><textarea required id="allowedSources" name="allowedSources"
                                                                 onfocus="$(this).next('.ft').slideDown('fast');"
                                                                 style="resize: vertical; width: 400px;"
                                                                 onchange="$('#abSettingsForm').submit();"><?= implode("\r\n", $abSettings->allowedSources) ?></textarea>
                <div class="ft" style="display: none;">
                    <div class="fileTreeDiv"></div>
                    <button onclick="addSelectionToList(this);  return false;">Add to list</button>
                </div>
            </div>
        </dd>
    </dl>

    <blockquote class='inline_help'>
        <p>Please set your appdata paths here. Appdata paths are paths, which holds your docker data. The
            default path is <code>/mnt/user/appdata</code> or <code>/mnt/cache/appdata</code>.<br/>
            If you use any other path, put it in here. If you use multiple appdata paths, set every path via the file
            browser or paste it here. <b>Everything within those set paths</b> will be considered as "internal" volume
            (see below).</p>
        <p><b>IMPORTANT:</b> This plugin differentiates between internal and external volume paths.<br/>
            <b>Internal</b> ones are volume mappings, which store the main appdata
            (<code>/mnt/user/appdata/mariadb/</code> would be such a volume). These will be backed up always!<br/>
            <b>External</b> ones are volume mappings, which can hold extra data,
            (<code>/mnt/user/Downloads/jDownlaoder</code> would be such a volume). These will be backed up optionally
            only.
        </p>
        <p>The plugin detects every volume mapping within your set "appdata source(s)" as internal ones. Everything else
            is being detected as external.</p>
        <p>The list of volume mappings is directly read from your container configuration!</p>
    </blockquote>

    <dl>

        <dt><b>Backup destination:</b></dt>
        <dd><input type='text' required class='ftAttach' id="destination" name="destination"
                   value="<?= $abSettings->destination ?>"
                   data-pickfilter="HIDE_FILES_FILTER" data-pickfolders="true"></dd>

        <dt><b>Use Compression?</b></dt>
        <dd><select id='compression' name="compression" data-setting="<?= $abSettings->compression ?>"
                    onchange="checkMultiCoreCpuCount();">
                <option value='no'>No</option>
                <option value='yes'>Yes, normal</option>
                <option value='yesMulticore'>Yes, multicore</option>
            </select>
        </dd>
    </dl>

    <dl id="compressionCpuLimit_dl">
        <dt><b>How many cores should be used?</b></dt>
        <dd><select id='compressionCpuLimit' name="compressionCpuLimit"
                    data-setting="<?= $abSettings->compressionCpuLimit ?>">
                <option value='0'>All cores</option>
                <?php
                $cores = 0;
                exec("nproc", $cores);
                for ($i = 1; $i < $cores[0]; $i++) {
                    echo "<option value='$i'>$i</option>";
                }
                ?>
            </select>
        </dd>
    </dl>

    <blockquote class='inline_help'>
        <p><b>Yes, normal</b>: Uses normal gzip compression</p>
        <p><b>Yes, multicore</b>: Uses <a href="https://facebook.github.io/zstd/" target="_blank">zstdmt</a> for
            compression. Please
            note, that this <i>could</i> decrease other system services during backup.</p>
    </blockquote>

    <dl>
        <dt><b>Backup the flash drive?</b></dt>
        <dd><select id='flashBackup' name="flashBackup" data-setting="<?= $abSettings->flashBackup ?>"
                    onchange="checkFlashBackupCopy();">
                <option value='yes'>Yes</option>
                <option value='no'>No</option>
            </select></dd>
    </dl>

    <blockquote class='inline_help'>
        <p>This puts a compressed copy of your flash drive inside the backup as well.</p>
    </blockquote>

    <dl id="flashBackupCopy_dl">
        <dt>
            <div style="display: table; line-height: 1em;"><b>Copy the flash backup to a custom destination</b><br/>This
                is optional
            </div>
        </dt>
        <dd><input style="width: 500px;" type='text' class='ftAttach' id="flashBackupCopy" name="flashBackupCopy"
                   value="<?= $abSettings->flashBackupCopy ?>"
                   data-pickroot="/mnt/"
                   data-pickfolders/></dd>
    </dl>

    <dl>
        <dt><b>Backup VM meta?</b></dt>
        <dd><select id='backupVMMeta' name="backupVMMeta" data-setting="<?= $abSettings->backupVMMeta ?>">
                <option value='yes'>Yes</option>
                <option value='no'>No</option>
            </select></dd>
    </dl>

    <blockquote class='inline_help'>
        <p>This saves <code>/etc/libvirt/qemu</code></p>
    </blockquote>

    <div class="title" onclick="$(this).next().show();"><span class="left"><i class="fa fa-cog title"></i>Advanced settings <small>| Some special/dangerous settings - Click to open</small></span>
    </div>
    <div style="display: none;">
        <blockquote>These settings are the <b>global defaults</b> for all containers. You can adjust them per container
            if you want.
        </blockquote>
        <dl>
            <dt>
                <div style="display: table; line-height: 1em;"><b>Skip stopping of containers?</b><br/><small>This will
                        skip stopping containers and leaves them running. Could lead to broken backup for
                        containers!</small>
                </div>
            </dt>
            <dd><select id='verifyBackup' name="defaults[dontStop]"
                        data-setting="<?= $abSettings->defaults['dontStop'] ?>">
                    <option value='no'>No</option>
                    <option value='yes'>Yes</option>
                </select>
            </dd>

            <dt>
                <div style="display: table; line-height: 1em;"><b>Verify Backup?</b><br/><small>Normally, tar detects
                        any
                        errors during backup. This option just adds an extra layer of security</small>
                </div>
            </dt>
            <dd><select id='verifyBackup' name="defaults[verifyBackup]"
                        data-setting="<?= $abSettings->defaults['verifyBackup'] ?>">
                    <option value='yes'>Yes</option>
                    <option value='no'>No</option>
                </select>
            </dd>

            <dt>
                <div style="display: table; line-height: 1em;"><b>Ignore errors during backup?</b><br/><small>This can
                        lead to
                        broken backups - Only enable if you know what you
                        do!</small>
                </div>
            </dt>
            <dd><select id='ignoreBackupErrors' name="defaults[ignoreBackupErrors]"
                        data-setting="<?= $abSettings->defaults['ignoreBackupErrors'] ?>">
                    <option value='yes'>Yes</option>
                    <option value='no'>No</option>
                </select>
            </dd>

            <dt>
                <div style="display: table; line-height: 1em;"><b>Enable <code>--ignore-case</code> for
                        tar?</b><br/><small>This ignores case sensitivity for exclusions.</small>
                </div>
            </dt>
            <dd><select id='ignoreExclusionCase' name="ignoreExclusionCase"
                        data-setting="<?= $abSettings->ignoreExclusionCase ?>">
                    <option value='yes'>Yes</option>
                    <option value='no'>No</option>
                </select>
            </dd>

        </dl>
    </div>

    <div class="title"><span class="left"><i
                    class="fa fa-clock-o title"></i>Notifications and scheduling</span>
    </div>

    <dl>
        <dt><b>Notification Settings:</b></dt>
        <dd><select id='notification' name="notification" data-setting="<?= $abSettings->notification ?>">
                <option value='<?= ABHelper::LOGLEVEL_ERR ?>'>Errors Only</option>
                <option value='<?= ABHelper::LOGLEVEL_WARN ?>'>Warnings and errors</option>
                <option value='disabled'>Disabled</option>
            </select>
        </dd>

        <dt><b>Create success notification:</b></dt>
        <dd><select id='successLogWanted' name="successLogWanted" data-setting="<?= $abSettings->successLogWanted ?>">
                <option value='no'>No</option>
                <option value='yes'>Yes</option>
            </select>
        </dd>

        <dt><b>Send notification if containers were updated:</b></dt>
        <dd><select id='updateLogWanted' name="updateLogWanted" data-setting="<?= $abSettings->updateLogWanted ?>">
                <option value='no'>No</option>
                <option value='yes'>Yes</option>
            </select>
        </dd>

        <dt><b>Scheduled Backup Frequency</b></dt>
        <dd><select id='backupFrequency' name="backupFrequency" onchange="checkBackupFrequency();"
                    data-setting="<?= $abSettings->backupFrequency ?>">
                <option value='disabled'>Disabled</option>
                <option value='daily'>Daily</option>
                <option value='weekly'>Weekly</option>
                <option value='monthly'>Monthly</option>
                <option value='custom'>Custom</option>
            </select>
        </dd>

        <dt><b>Day of Week:</b></dt>
        <dd><select id='backupFrequencyDay' name="backupFrequencyWeekday"
                    data-setting="<?= $abSettings->backupFrequencyWeekday ?>">
                <option value='0'>Sunday</option>
                <option value='1'>Monday</option>
                <option value='2'>Tuesday</option>
                <option value='3'>Wednesday</option>
                <option value='4'>Thursday</option>
                <option value='5'>Friday</option>
                <option value='6'>Saturday</option>
            </select>
        </dd>

        <dt><b>Day of Month:</b></dt>
        <dd><select id='backupFrequencyDayOfMonth' name="backupFrequencyDayOfMonth"
                    data-setting="<?= $abSettings->backupFrequencyDayOfMonth ?>">
                <option value='1'>1st</option>
                <option value='2'>2nd</option>
                <option value='3'>3rd</option>
                <option value='4'>4th</option>
                <option value='5'>5th</option>
                <option value='6'>6th</option>
                <option value='7'>7th</option>
                <option value='8'>8th</option>
                <option value='9'>9th</option>
                <option value='10'>10th</option>
                <option value='11'>11th</option>
                <option value='12'>12th</option>
                <option value='13'>13th</option>
                <option value='14'>14th</option>
                <option value='15'>15th</option>
                <option value='16'>16th</option>
                <option value='17'>17th</option>
                <option value='18'>18th</option>
                <option value='19'>19th</option>
                <option value='20'>20th</option>
                <option value='21'>21st</option>
                <option value='22'>22nd</option>
                <option value='23'>23rd</option>
                <option value='24'>24th</option>
                <option value='25'>25th</option>
                <option value='26'>26th</option>
                <option value='27'>27th</option>
                <option value='28'>28th</option>
                <option value='29'>29th</option>
                <option value='30'>30th</option>
                <option value='31'>31st</option>
            </select>
        </dd>

        <dt><b>Hour:</b></dt>
        <dd><input type="number" min="00" max="23" id='backupFrequencyHour' name="backupFrequencyHour"
                   value="<?= $abSettings->backupFrequencyHour ?>"/></dd>

        <dt><b>Minute:</b></dt>
        <dd><input type="number" min="00" max="59" id='backupFrequencyMinute' name="backupFrequencyMinute"
                   value="<?= $abSettings->backupFrequencyMinute ?>"/></dd>

        <dt><b>Custom Cron Entry:</b></dt>
        <dd><input type='text' id='backupFrequencyCustom' name="backupFrequencyCustom"
                   value="<?= $abSettings->backupFrequencyCustom ?>"
                   placeholder="Setting this, will disable the other options"/></dd>
    </dl>


    <div class="title"><span class="left"><i class="fa fa-docker title"></i>Docker specific settings</span></div>

    <p><b>General note</b>: This plugin always backup every unknown (new) container with the default settings. In this
        section you can set settings that deviate from the defaults.</p>

    <dl>
        <dt><b>Update containers after backup?</b></dt>
        <dd><select id='updateContainer' name="defaults[updateContainer]"
                    data-setting="<?= $abSettings->defaults['updateContainer'] ?>">
                <option value='yes'>Yes</option>
                <option value='no'>No</option>
            </select>
        </dd>
    </dl>

    <div style="display: flex;">
        <div class="dockerSettings" style="flex-grow: 1; flex-basis: 0;">
            <div class="title"><span class="left"><i class="fa fa-docker title"></i>Per container settings. <b>Click on container name to open</b></span>
            </div>

            <datalist id="containerGroups">
                <?php
                foreach ($abSettings->getContainerGroups() as $group => $members) {
                    echo "<option value=\"$group\"></option>";
                }
                ?>
            </datalist>

            <?php
            $dockerClient  = new DockerClient();
            $allContainers = $dockerClient->getDockerContainers();

            foreach ($allContainers as $container) {
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
                $containerSetting = $abSettings->getContainerSpecificSettings($container['Name'], false);
                $realContainerSetting = print_r($abSettings->getContainerSpecificSettings($container['Name']), true);

                if (empty($volumes)) {
                    $volumes = "<b>No volumes - container will NOT being backed up!</b>";
                } else {
                    foreach ($volumes as $index => $volume) {
                        $excluded        = in_array($volume, $containerSetting['exclude']) ? ' - <abbr style="color: red; font-weight: bold;" title="Will not being backed up! See exclusions list below!">EXCLUDED!</abbr> ' : false;
                        $internalVolume  = ABHelper::isVolumeWithinAppdata($volume);
                        $volumes[$index] = '<span class="fa ' . (!$internalVolume ? 'fa-external-link' : 'fa-folder') . '"></span> <code style="cursor:pointer;" data-container="' . $container['Name'] . '" data-internal="' . ($internalVolume ? 'true' : 'false') . '" data-excluded="' . ($excluded ? 'true' : 'false') . '" onclick="addVolumeToExclude(this);">' . $volume . '</code>' . $excluded . '<span style="display: none;" class="multiVolumeWarn"> - <a target="_blank" href="https://forums.unraid.net/topic/137710-plugin-appdatabackup/?do=findComment&comment=1250363">used in multiple containers!</a></span>';
                    }
                    $volumes = implode('<br />', $volumes);
                }

                $containerExcludes = implode("\r\n", $containerSetting['exclude']);

                echo <<<HTML
<style>
.containerSettingsDt {
    overflow: hidden;
    white-space: nowrap
}
.containerSettingsDt:after {
    opacity: 0.1;
    content: "  _____________________________________________________________________________________________________________________________________________________________________";
}
</style>
<div style="display: none" id="actualContainerSettings_{$container['Name']}">$realContainerSetting</div>
        <dl>
        <dt class="containerSettingsDt"><img alt="pic" src='$image' height='16' /> <i title='{$container['Image']}' class='fa fa-info-circle'></i> <abbr title='Click for advanced settings'>{$container['Name']}$plexContainerNameSuffix</abbr> <span id="containerMultiMappingIssue_{$container['Name']}" style="display: none; color: darkorange;">WARN: Multi mapping detected!</span></dt>
        <dd><label for="{$container['Name']}_skip">&nbsp;&nbsp;Skip?</label>
        <select name="containerSettings[{$container['Name']}][skip]" id="{$container['Name']}_skip" data-setting="{$containerSetting['skip']}">
            <option value="no">No</option>
            <option value="yes">Yes</option>
    </select>
    </dd>
        </dl>

<blockquote class='inline_help'>
<dl>
$plexHint
<dt>Configured volumes <small>- (Click to exclude)</small><br /><small><abbr style="cursor:help;" title="For info, open the 'Appdata source(s)' help"><i class="fa fa-folder"></i> Internal volume | <i class="fa fa-external-link"></i> External volume</abbr></small></dt>
<dd><div style="display: table">$volumes</div></dd>
<br />

<dt>Member of group <small>- <a href="https://forums.unraid.net/topic/137710-plugin-appdatabackup/?do=findComment&comment=1250363" target="_blank">Click here</a> and scroll to "Hints" for more</small></dt>
<dd><div style="display: table"><input list="containerGroups" type="text" placeholder="None - Double click for a list" id='{$container['Name']}_group' name="containerSettings[{$container['Name']}][group]" value="{$containerSetting['group']}" onkeyup="$(this).next().show();" onchange="$(this).next().show();" autocomplete="off" /><span style="color: red; display: none;"><br />To adjust group order, save your changes.</span></div></dd>

<dt>Save external volumes?</dt>
<dd><select id='{$container['Name']}_backupExtVolumes' name="containerSettings[{$container['Name']}][backupExtVolumes]" data-setting="{$containerSetting['backupExtVolumes']}" >
		<option value='no'>No</option>
		<option value='yes'>Yes</option>
	</select></dd>
	
	<dt>Update container after backup?</dt>
    <dd><select id='{$container['Name']}_updateContainer' name="containerSettings[{$container['Name']}][updateContainer]" data-setting="{$containerSetting['updateContainer']}">
            <option value=''>Use standard</option>
            <option value='yes'>Yes</option>
            <option value='no'>No</option>
        </select>
    </dd>
    
    <dt>Excluded folders/files<br /><small>One path/pattern per line. See belows "Global exclusions" for more examples.</small></dt>
    <dd><div style="display: table; width: 300px;"><textarea id="{$container['Name']}_exclude" name="containerSettings[{$container['Name']}][exclude]" onfocus="$(this).next('.ft').slideDown('fast');" style="resize: vertical; width: 400px;">$containerExcludes</textarea><div class="ft" style="display: none;"><div class="fileTreeDiv"></div><button onclick="addSelectionToList(this);  return false;">Add to list</button></div></div></dd>
    



<div onclick="$(this).next().toggle();"><a style="cursor:pointer;">Show advanced options</a></div>
	<div style="display: none;">
	
	<dt>Skip backup? <small>Only stop/start</small></dt>
<dd><select id='{$container['Name']}_skipBackup' name="containerSettings[{$container['Name']}][skipBackup]" data-setting="{$containerSetting['skipBackup']}" >
		<option value='no'>No, do backup as well</option>
		<option value='yes'>Yes, skip backup and do stop/start only</option>
	</select></dd>
	
	<dt>Verify Backup?</dt>
<dd><select id='{$container['Name']}_verifyBackup' name="containerSettings[{$container['Name']}][verifyBackup]" data-setting="{$containerSetting['verifyBackup']}" >
		<option value=''>Use standard</option>
		<option value='yes'>Yes</option>
		<option value='no'>No</option>
	</select></dd>
	
<dt>Ignore errors during backup?</dt>
<dd>
    <select id='{$container['Name']}_ignoreBackupErrors' name="containerSettings[{$container['Name']}][ignoreBackupErrors]" data-setting="{$containerSetting['ignoreBackupErrors']}">
        <option value=''>Use standard</option>
        <option value='yes'>Yes</option>
		<option value='no'>No</option>
	</select>
</dd>
    <dt>Skip stopping of container? <small><abbr title="This will skip stopping this container and leaves it running. Could lead to broken backup for this container!">NOT RECOMMENDED!</abbr></small></dt>
    <dd><select id='{$container['Name']}_dontStop' name="containerSettings[{$container['Name']}][dontStop]" data-setting="{$containerSetting['dontStop']}" >
            <option value=''>Use standard</option>
            <option value='no'>No</option>
            <option value='yes'>Yes</option>
        </select></dd>
        
	</div>

</dl>
</blockquote>
HTML;


            }
            ?>

        </div>
        <div style="flex-grow: 1; flex-basis: 0; padding-left: 10px; max-width: 35%;">
            <div class="title"><span class="left"><i class="fa fa-sort title"></i>Start order</span></div>
            <p>This defines the start sequence. Stop would be this order in reverse.</p>
            <input type="hidden" id="containerOrder" name="containerOrder"/>
            <ul class="sortable" id="containerOrderSortable">
                <?php
                $sortedContainers = ABHelper::sortContainers($allContainers, $abSettings->containerOrder, false, false);
                foreach ($sortedContainers as $container) {
                    $isGroup = $container['isGroup'];
                    $name         = $container['Name'] ?? key($container);
                    $internalName = $isGroup ? '__grp__' . $name : $name;
                    $image        = (empty($container['Icon']) ? '/plugins/dynamix.docker.manager/images/question.png' : $container['Icon']);
                    $imageHtml    = $isGroup ? '<i class="fa fa-folder" style="padding-right: 10px;"></i>' : '<img src="' . $image . '" height="16" />';
                    echo <<<HTML
<li id="containerOrder_{$internalName}"><i class="fa fa-sort"></i> $imageHtml $name</li>
HTML;

                }
                ?>
            </ul>

            <?php
            foreach ($abSettings->getContainerGroups() as $group => $members) {
                ?>
                <div class="title"><span class="left"><i
                                class="fa fa-sort title"></i>Start order for group <?= $group ?></span></div>
                <p>This defines the start sequence. Stop would be this order in reverse.<br/><b>All containers inside a
                        group will be stopped (by their order), backed up and then started again.</b></p>
                <input type="hidden" id="containerGroupOrder_<?= $group ?>" name="containerGroupOrder[<?= $group ?>]"/>
                <ul class="sortable" id="containerGroupOrder_<?= $group ?>_Sortable">
                    <?php
                    $sortedContainers = ABHelper::sortContainers($allContainers, $abSettings->containerGroupOrder[$group] ?? [], false, false, $members);
                    foreach ($sortedContainers as $container) {
                        $image = empty($container['Icon']) ? '/plugins/dynamix.docker.manager/images/question.png' : $container['Icon'];
                        echo <<<HTML
<li id="containerGroupOrder[{$group}]={$container['Name']}"><i class="fa fa-sort"></i> <img src="$image" height="16" /> {$container['Name']}</li>
HTML;

                    }
                    ?>
                </ul>
                <?php
            }
            ?>
        </div>
    </div>

    <div class="title"><span class="left"><i class="fa fa-i-cursor title"></i>Custom scripts | <small><i
                        class="fa fa-info"></i> Those must return exit code 0 for success detection</small></span></div>

    <blockquote>
        <p>Scripts must be stored anywhere outside <code>/boot</code> because the boot drive (FAT32) does not support
            script executions from it!</p>
    </blockquote>

    <dl>
        <dt>Pre-run script</dt>
        <dd><input style="width: 500px;" type='text' class='ftAttach' id="preRunScript" name="preRunScript"
                   value="<?= $abSettings->preRunScript ?>"
                   data-pickroot="/mnt/"/></dd>
    </dl>

    <blockquote class='inline_help'>
        <p>Runs the selected script BEFORE ANYTHING is done. Sent arguments: <code>pre-run</code>, <code>destination
                path</code></p>
    </blockquote>

    <dl>
        <dt>Pre-backup script</dt>
        <dd><input style="width: 500px;" type='text' class='ftAttach' id="preBackupScript" name="preBackupScript"
                   value="<?= $abSettings->preBackupScript ?>"
                   data-pickroot="/mnt/"/></dd>
    </dl>

    <blockquote class='inline_help'>
        <p>Runs the selected script BEFORE the backup is starting. Sent arguments: <code>pre-backup</code>, <code>destination
                path</code></p>
    </blockquote>

    <dl>
        <dt>Pre-container-backup script</dt>
        <dd><input style="width: 500px;" type='text' class='ftAttach' id="preContainerBackupScript" name="preContainerBackupScript"
                   value="<?= $abSettings->preContainerBackupScript ?>"
                   data-pickroot="/mnt/"/></dd>
    </dl>

    <blockquote class='inline_help'>
        <p>Runs the selected script for each container immediately BEFORE creating the tarfile. Sent arguments: <code>pre-container</code>,
            <code>container name</code></p>
    </blockquote>

    <dl>
        <dt>Post-container-backup script</dt>
        <dd><input style="width: 500px;" type='text' class='ftAttach' id="postContainerBackupScript" name="postContainerBackupScript"
                   value="<?= $abSettings->postContainerBackupScript ?>"
                   data-pickroot="/mnt/"/></dd>
    </dl>

    <blockquote class='inline_help'>
        <p>Runs the selected script for each container immediately AFTER creating the tarfile. Sent arguments: <code>post-container</code>,
            <code>container name</code></p>
    </blockquote>

    <dl>
        <dt>Post-backup script</dt>
        <dd><input style="width: 500px;" type='text' class='ftAttach' id="postBackupScript" name="postBackupScript"
                   value="<?= $abSettings->postBackupScript ?>"
                   data-pickroot="/mnt/"/></dd>
    </dl>

    <blockquote class='inline_help'>
        <p>Runs the selected script AFTER the backup is done (before containers would start). Sent arguments: <code>post-backup</code>,
            <code>destination path</code></p>
    </blockquote>

    <dl>
        <dt>Post-run script</dt>
        <dd><input style="width: 500px;" type='text' class='ftAttach' id="postRunScript" name="postRunScript"
                   value="<?= $abSettings->postRunScript ?>"
                   data-pickroot="/mnt/"/></dd>
    </dl>

    <blockquote class='inline_help'>
        <p>Runs the selected script AFTER everything is done. Sent arguments: <code>post-run</code>, <code>destination
                path</code>, <code>true|false</code> (true on backup success, false otherwise)</p>
    </blockquote>

    <div class="title"><span class="left"><i class="fa fa-plus-square title"></i>Some extra options</span></div>

    <dl>
        <dt>Include extra files/folders</dt>
        <dd>
            <div style="display: table; width: 300px;"><textarea id="includeFiles" name="includeFiles"
                                                                 onfocus="$(this).next('.ft').slideDown('fast');"
                                                                 style="resize: vertical; width: 400px;"><?= implode("\r\n", $abSettings->includeFiles) ?></textarea>
                <div class="ft" style="display: none;">
                    <div class="fileTreeDiv"></div>
                    <button onclick="addSelectionToList(this);  return false;">Add to list</button>
                </div>
            </div>
        </dd>
    </dl>
    <blockquote class='inline_help'>
        <p>Those files will be packed into "extra_files.tar.gz"</p>
    </blockquote>

    <dl>
        <dt>Global exclusion list</dt>
        <dd>
            <div style="display: table; width: 300px;"><textarea id="globalExclusions" name="globalExclusions"
                                                                 style="resize: vertical; width: 400px;"><?= implode("\r\n", $abSettings->globalExclusions) ?></textarea>
            </div>
        </dd>
    </dl>
    <blockquote class='inline_help'>
        <p>With this you can define exclusions which will be used as global exclusion</p>
        <p>You can use parts of paths and/or wildcards like <code>*.png</code>, <code>music/*.m4a</code>,
            <code>logs</code>. Any folder/file paths matching this patterns will be excluded!<br/></p>
    </blockquote>

    <dl>
        <dt>Done?</dt>
        <dd><input type="submit" value="Save" id="submitBtn"/> <input type="reset" value="Discard"/>
            <button id="manualBackup" style="margin-left: 15px;">Manual backup</button>
        </dd>
    </dl>
</form>

<div class="title"><span class="left"><i class="fa fa-life-ring title"></i>Help & other things</span></div>
<p>If you need assistance, you can ask in the unraid community for help.</p>
<dl>
    <dt>Plugin thread @ Unraid community</dt>
    <dd><a href="<?= ABSettings::$supportUrl ?>" target="_blank">Open</a>
    </dd>

    <dt>Maintainer</dt>
    <dd>2022 - now: <a href="https://forums.unraid.net/profile/140912-kluthr/" target="_blank">Robin</a> <a
                href="https://kluthr.de" target="_blank">Kluth</a> | 2015-2022 <a
                href="https://forums.unraid.net/profile/10290-squid/" target="_blank">Andrew Zawadzki</a></dd>

    <dt>Want to say "Thank You"?</dt>
    <dd>You're welcome! 😊 Thanks for using! <abbr title="All community developers">We</abbr> make those plugins
        with ❤️ (and a lot of ☕). If you like the work, you can donate via <a
                href="https://www.paypal.com/donate/?hosted_button_id=KE7Z3KLEEY484"
                                                                              target="_blank"><i
                    class="fa fa-paypal"></i> PayPal</a>.
    </dd>

    <dt>GitHub repository</dt>
    <dd><a href="https://github.com/Commifreak/unraid-appdata.backup" target="_blank"><i class="fa fa-github"></i> Open</a>
    </dd>

    <dt>Used IDE</dt>
    <dd>JetBrains PHPStorm ❤️</dd>
</dl>

<script src="<?php autov('/webGui/javascript/jquery.filetree.js') ?>" charset="utf-8"></script>
<script>
    $(function () {
        $('.fileTreeDiv').fileTree({
            // root: $('#source').val(),
            multiSelect: true,
            //filter: "HIDE_FILES_FILTER",
            //folderEvent: "nothing"
        });

        $('.ftAttach').fileTreeAttach();
        $('.ftAttach').attr('placeholder', 'Please click to select');
        $('.sortable').sortable();

        /**
         * Select correct setting value
         */
        console.debug('Setting select settings...');
        $('select[data-setting]').each(function (index) {
            console.debug($(this).attr('name'), $(this).data('setting'));
            $(this).find('option[value="' + $(this).data('setting') + '"]').prop('selected', true);
        });


        $('#manualBackup').on('click', function () {
            swal({
                title: "Proceed?",
                text: "Are you sure you want to start a manual backup?",
                type: 'warning',
                html: true,
                showCancelButton: true,
                confirmButtonText: "Yep",
                cancelButtonText: "Nah"
            }, function () {
                $.ajax(url, {
                    data: {action: 'manualBackup'}
                }).always(function (data) {
                    $('#tab3').click();
                });
            });
            return false;
        });


        //if (typeof caPluginUpdateCheck === "function") {
        //    caPluginUpdateCheck("appdata.backup<?= $beta ?>.plg", {name: "Appdata Backup"});
        //}


        checkBackupFrequency();
        checkFlashBackupCopy();
        checkMultiCoreCpuCount();
        checkVolumesForDuplicates();


    });

    $('#abSettingsForm').on('submit', function () {
        console.debug("SUBMIT!");
        let mainValue = $('#containerOrderSortable').sortable('serialize', {
            expression: /(.+?)_(.+)/
        });
        console.debug('Main order value: ', mainValue);
        $('#containerOrder').val(mainValue);

        $("input[id^='containerGroupOrder']").each(function (index) {
            console.debug("Processing groupOrder " + $(this).attr('id'));
            let groupValue = $('#' + $(this).attr('id') + '_Sortable').sortable('serialize', {
                expression: /(.+?)=(.+)/
            });
            console.debug('Group order value', groupValue);
            $(this).val(groupValue);
        });
        console.debug('Final form:', $(this).serialize());
    });


    function addSelectionToList(element) {
        $el = $(element).prev().find("input:checked");
        $textarea = $(element).parent().prev();

        console.debug($el, $textarea);


        if ($el.length !== 0) {
            var checked = $el
                .map(function () {
                    return $(this).parent().find('a:first').attr('rel');
                })
                .get()
                .join('\n');

            if ($textarea.val() === "") {
                $textarea.val(checked);
            } else {
                $textarea.val($textarea.val() + "\n" + checked);
            }
        }
        $(element).parent().slideUp('fast', function () {
            $el.prop('checked', false);
        });
    }

    function addVolumeToExclude(element) {
        $path = $(element).text();
        $excludeTextarea = $('#' + $(element).data('container') + '_exclude');

        if ($excludeTextarea.val().split(/\r?\n|\r|\n/g).includes($path)) { // If existing inside textarea
            console.log("Not adding this volume to exclusion: already listed!")
            return;
        }

        if ($excludeTextarea.val() === "") {
            $excludeTextarea.val($path);
        } else {
            $excludeTextarea.val($excludeTextarea.val() + "\n" + $path);
        }
    }

    function checkBackupFrequency() {
        $('#backupFrequencyDay, #backupFrequencyDayOfMonth, #backupFrequencyHour, #backupFrequencyMinute, #backupFrequencyCustom').prop('disabled', true);
        switch ($('#backupFrequency').val()) {
            case 'disabled':
                $('#backupFrequencyDay, #backupFrequencyDayOfMonth, #backupFrequencyHour, #backupFrequencyMinute, #backupFrequencyCustom').prop('disabled', true);
                break;
            case 'daily':
                $('#backupFrequencyHour, #backupFrequencyMinute').prop('disabled', false);
                break;
            case 'weekly':
                $('#backupFrequencyHour, #backupFrequencyMinute, #backupFrequencyDay').prop('disabled', false);
                break;
            case 'monthly':
                $('#backupFrequencyHour, #backupFrequencyMinute, #backupFrequencyDayOfMonth').prop('disabled', false);
                break;
            default:
                $('#backupFrequencyCustom').prop('disabled', false);
                break;
        }
    }

    function checkFlashBackupCopy() {
        switch ($('#flashBackup').val()) {
            case 'no':
                $('#flashBackupCopy').val('');
                $('#flashBackupCopy_dl').fadeOut();
                break;
            case 'yes':
                $('#flashBackupCopy_dl').fadeIn();
                break;
        }
    }

    function checkMultiCoreCpuCount() {
        console.debug('compression setting: ', $('#compression').val());
        if ($('#compression').val() === 'yesMulticore') {
            $('#compressionCpuLimit_dl').fadeIn();
        } else {
            $('#compressionCpuLimit_dl').fadeOut();
        }
    }

    function checkVolumesForDuplicates() {

        let volumeMatrix = [];
        let affectedMappings = [];

        $("code[data-container]").each(function () {
            let container = $(this).data('container');
            let mapping = $(this).text();

            if ($(this).data('excluded')) {
                console.debug('Ignore ' + mapping + ': Excluded!');
                return; // Ignore mapping, its excluded
            }

            if (!$(this).data('internal') && $('#' + container + '_backupExtVolumes').val() == 'no') {
                console.debug('Ignore ' + mapping + ': Is external and external should not be backed up!');
                return;
            }

            console.debug("CV: processing", container, mapping);

            if (volumeMatrix.includes(mapping)) {
                affectedMappings.push(mapping);
                console.debug("CV: mapping affected!");
            } else {
                volumeMatrix.push(mapping);
            }
        });

        console.debug("Volume dup check (affected/matrix): ", affectedMappings, volumeMatrix);

        affectedMappings.forEach(function (element) {
            let codeElems = $('code[data-container]').filter(function () {
                return $(this).text() === element;
            });
            console.debug("Affected (filtered) code elems", codeElems);
            codeElems.each(function () {
                console.debug("Affected Warn display: element/container:", element, $(this).data('container'));
                let nextMultiWarnSpan = $(this).next('.multiVolumeWarn');
                if (nextMultiWarnSpan.length) {
                    $('#containerMultiMappingIssue_' + $(this).data('container')).show();
                    nextMultiWarnSpan.show();
                } else {
                    console.error("MultiWarnSpan not found!");
                }
            });
        });
    }

    function copyConfigFromProd() {
        $.ajax(url + '?action=copyConfigFromProd').done(function (data) {
            alert(data);
            window.location.href = window.location;
        }).fail(function (data) {
            alert('Something went wrong :/')
        });
    }
</script>
