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

if (!file_exists(ABSettings::$dockerIniFile)) {
    echo "<h1>Oooopsie!</h1><p>The docker config could not be found!</p>";
    return;
}


/**
 * POST Handling
 */
if ($_POST) {
    if (!file_exists(ABSettings::$pluginDir)) {
        mkdir(ABSettings::$pluginDir);
    }

    //Hack the order string
    parse_str($_POST['containerOrder'], $containerOrder);
    $_POST = array_merge($_POST, $containerOrder);
    file_put_contents(ABSettings::getConfigPath(), json_encode($_POST, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

$abSettings = new ABSettings();

if ($_POST) {
    $abSettings->checkCron();
}

$dockerCfg = parse_ini_file(ABSettings::$dockerIniFile);


?>
<link type="text/css" rel="stylesheet" href="<?php autov('/webGui/styles/jquery.filetree.css') ?>">
<style>
    .fileTree {
        width: 300px;
        max-height: 150px;
        overflow-y: scroll;
        overflow-x: hidden;
        position: absolute;
        z-index: 100;
        display: none
    }

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

</style>

<div class="title"><span class="left"><i class="fa fa-hand-peace-o title"></i>Welcome to appdata backup</span></div>
<p>Welcome to the appdata backup plugin!</p>
<p>This plugin allows you to back up and restore all your appdata content! It takes care of everything (stop/start
    docker containers) including some extras (update docker containers)</p>

<div class="title"><span class="left"><i class="fa fa-cog title"></i>Main settings</span></div>

<form method="post">
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

        <dt><b>Appdata Share (Source):</b></dt>
        <dd><?= $dockerCfg['DOCKER_APP_CONFIG_PATH'] ?><br/><small>The source share is being read from the docker
                default appdata path. All container paths are read from their
                volume configuration</small>
        </dd>

        <dt><b>Destination Share:</b></dt>
        <dd><input type='text' required class='ftAttach' id="destination" name="destination"
                   value="<?= $abSettings->destination ?>"
                   data-pickfilter="HIDE_FILES_FILTER" data-pickfolders="true"></dd>

        <dt><b>Use Compression?</b></dt>
        <dd><select id='compression' name="compression" data-setting="<?= $abSettings->compression ?>">
                <option value='no'>No</option>
                <option value='yes'>Yes, normal</option>
                <option value='yesMulticore'>Yes, multicore</option>
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
        <dt>
            <div style="display: table; line-height: 1em;"><b>Verify Backup?</b><br/><small>Normally, tar detects any
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
            <div style="display: table; line-height: 1em;"><b>Ignore errors during backup?</b><br/>This can lead to
                broken backups - Only enable if you know what you
                do!
            </div>
        </dt>
        <dd><select id='ignoreBackupErrors' name="defaults[ignoreBackupErrors]"
                    data-setting="<?= $abSettings->defaults['ignoreBackupErrors'] ?>">
                <option value='yes'>Yes</option>
                <option value='no'>No</option>
            </select>
        </dd>

    </dl>

    <dl>
        <dt><b>Backup the flash drive?</b></dt>
        <dd><select id='flashBackup' name="flashBackup" data-setting="<?= $abSettings->flashBackup ?>">
                <option value='yes'>Yes</option>
                <option value='no'>No</option>
            </select></dd>
    </dl>

    <blockquote class='inline_help'>
        <p>This puts a compressed copy of your flash drive inside the backup as well.</p>
    </blockquote>

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

    <div class="title"><span class="left"><i
                    class="fa fa-clock-o title"></i>Notifications, scheduling and retention</span>
    </div>

    <dl>
        <dt><b>Notification Settings:</b></dt>
        <dd><select id='notification' name="notification" data-setting="<?= $abSettings->notification ?>">
                <option value='errors'>Errors Only</option>
                <option value='warning'>Warnings and errors</option>
                <option value='disabled'>Disabled</option>
            </select>
        </dd>

        <dt><b>Scheduled Backup Frequency</b></dt>
        <dd><select id='backupFrequency' name="backupFrequency" data-setting="<?= $abSettings->backupFrequency ?>">
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
            <div class="title"><span class="left"><i class="fa fa-docker title"></i>Per container settings</span></div>

            <?php
            $dockerClient  = new DockerClient();
            $allContainers = $dockerClient->getDockerContainers();

            foreach ($allContainers as $container) {
                $image   = empty($container['Icon']) ? '/plugins/dynamix.docker.manager/images/question.png' : $container['Icon'];
                $volumes = ABHelper::examineContainerVolumes($container);

                if (empty($volumes)) {
                    $volumes = "<b>No volumes - container will NOT being backed up!</b>";
                } else {
                    foreach ($volumes as $index => $volume) {
                        $volumes[$index] = '<span class="fa ' . (str_starts_with($volume, '/') ? 'fa-external-link' : 'fa-folder') . '"> <code>' . $volume . '</code></span>';
                    }
                    $volumes = implode('<br />', $volumes);
                }

                $containerSetting = $abSettings->getContainerSpecificSettings($container['Name'], false);

                echo <<<HTML
        <dl>
        <dt><img alt="pic" src='$image' height='16' /> <i title='{$container['Image']}' class='fa fa-info-circle'></i> <abbr title='Click for advanced settings'>{$container['Name']}</abbr></dt>
        <dd><label for="{$container['Name']}_skip">Skip?</label>
        <select name="containerSettings[{$container['Name']}][skip]" id="{$container['Name']}_skip" data-setting="{$containerSetting['skip']}">
            <option value="yes">Yes</option>
            <option value="no">No</option>
    </select>
    </dd>
        </dl>

<blockquote class='inline_help'>
<dl>
<dt>Configured volumes</dt>
<dd><div style="display: table">$volumes</div></dd>

<!--<dt>Save external volumes? <small>Those with an <i class="fa fa-external-link"></i></small></dt>
<dd><select id='{$container['Name']}_backupExtVolumes' name="containerSettings[{$container['Name']}][backupExtVolumes]" data-setting="{$containerSetting['backupExtVolumes']}" >
		<option value='no'>No</option>
		<option value='yes'>Yes</option>
	</select></dd>-->

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
	
<dt>Update container after backup?</dt>
<dd><select id='{$container['Name']}_updateContainer' name="containerSettings[{$container['Name']}][updateContainer]" data-setting="{$containerSetting['updateContainer']}">
        <option value=''>Use standard</option>
        <option value='yes'>Yes</option>
        <option value='no'>No</option>
    </select>
</dd>
	
<dt>Excluded folders/files<br />One folder/file per line. <a href="https://www.gnu.org/software/tar/manual/html_node/wildcards.html" target="_blank">tar's glob syntax</a> is used.</dt>
<dd><div style="display: table; width: 300px;"><textarea id="{$container['Name']}_exclude" name="containerSettings[{$container['Name']}][exclude]" onfocus="$(this).next('.ft').slideDown('fast');" style="resize: vertical; width: 400px;">{$containerSetting['exclude']}</textarea><div class="ft" style="display: none;"><div class="fileTreeDiv"></div><button onclick="addSelectionToList(this);  return false;">Add to list</button></div></div></dd>

<dt>Dont stop container? <small>NOT RECOMMENDED!</small></dt>
<dd><select id='{$container['Name']}_dontStop' name="containerSettings[{$container['Name']}][dontStop]" data-setting="{$containerSetting['dontStop']}" >
		<option value='no'>No</option>
		<option value='yes'>Yes</option>
	</select></dd>

</dl>
</blockquote>
HTML;


            }
            ?>

        </div>
        <div style="flex-grow: 1; flex-basis: 0; padding-left: 10px; max-width: 35%;">
            <div class="title"><span class="left"><i class="fa fa-sort title"></i>Start order</span></div>
            <p>This defines the start sequence. Stop would be this order in reverse. This list also defines the sequence
                for the <code>Stop, backup, start for each container</code> type.</p>
            <input type="hidden" id="containerOrder" name="containerOrder"/>
            <ul class="sortable" id="containerOrderSortable">
                <?php
                $sortedContainers = ABHelper::sortContainers($allContainers, $abSettings->containerOrder, false, false);
                foreach ($sortedContainers as $container) {
                    $image = empty($container['Icon']) ? '/plugins/dynamix.docker.manager/images/question.png' : $container['Icon'];
                    echo <<<HTML
<li id="containerOrder_{$container['Name']}"><i class="fa fa-sort"></i> <img src="$image" height="16" /> {$container['Name']}</li>
HTML;

                }
                ?>
            </ul>
        </div>
    </div>

    <div class="title"><span class="left"><i class="fa fa-i-cursor title"></i>Custom scripts | <small><i
                        class="fa fa-info"></i> Those must return exit code 0 for success detection</small></span></div>

    <dl>
        <dt>Pre-run script</dt>
        <dd><input style="width: 500px;" type='text' class='ftAttach' id="preRunScript" name="preRunScript"
                   value="<?= $abSettings->preRunScript ?>"
                   data-pickroot="/mnt/"/></dd>
    </dl>

    <blockquote class='inline_help'>
        <p>Runs the selected script BEFORE ANYTHING is done.</p>
    </blockquote>

    <dl>
        <dt>Pre-backup script</dt>
        <dd><input style="width: 500px;" type='text' class='ftAttach' id="preBackupScript" name="preBackupScript"
                   value="<?= $abSettings->preBackupScript ?>"
                   data-pickroot="/mnt/"/></dd>
    </dl>

    <blockquote class='inline_help'>
        <p>Runs the selected script BEFORE the backup is starting.</p>
    </blockquote>

    <dl>
        <dt>Post-backup script</dt>
        <dd><input style="width: 500px;" type='text' class='ftAttach' id="postBackupScript" name="postBackupScript"
                   value="<?= $abSettings->postBackupScript ?>"
                   data-pickroot="/mnt/"/></dd>
    </dl>

    <blockquote class='inline_help'>
        <p>Runs the selected script AFTER the backup is done (before containers would start).</p>
    </blockquote>

    <dl>
        <dt>Post-run script</dt>
        <dd><input style="width: 500px;" type='text' class='ftAttach' id="postRunScript" name="postRunScript"
                   value="<?= $abSettings->postRunScript ?>"
                   data-pickroot="/mnt/"/></dd>
    </dl>

    <blockquote class='inline_help'>
        <p>Runs the selected script AFTER everything is done.</p>
    </blockquote>

    <div class="title"><span class="left"><i class="fa fa-plus-square title"></i>Some extra options</span></div>

    <dl>
        <dt>Include extra files/folders</dt>
        <dd>
            <div style="display: table; width: 300px;"><textarea id="includeFiles" name="includeFiles"
                                                                 onfocus="$(this).next('.ft').slideDown('fast');"
                                                                 style="resize: vertical; width: 400px;"><?= $abSettings->includeFiles ?></textarea>
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
    <dd>Thanks! 😊 Thanks for using! You must know, <abbr title="All community developers">we</abbr> make those plugins
        with ❤️ (and a lot of ☕). If you like, you can donate via <a href="https://paypal.me/robinkluth/"
                                                                     target="_blank"><i class="fa fa-paypal"></i> PayPal</a>.
    </dd>

    <dt>GitHub repository</dt>
    <dd><a href="https://github.com/Commifreak/unraid-appdata.backup" target="_blank"><i class="fa fa-github"></i> Open</a>
    </dd>
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


        if (typeof caPluginUpdateCheck === "function") {
            caPluginUpdateCheck("appdata.backup<?= $beta ?>.plg", {name: "Appdata Backup"});
        }


    });

    $('#submitBtn').on('click', function () {
        $('#containerOrder').val($('#containerOrderSortable').sortable('serialize', {
            expression: /(.+?)[-=_](.+)/
        }));
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
            console.debug(checked);
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
</script>