<?php

use unraid\plugins\AppdataBackup\ABHelper;
use unraid\plugins\AppdataBackup\ABSettings;

/** @var $dockerCfg array */

if (!ABHelper::isArrayOnline()) {
    echo "<h1>Oooopsie!</h1><p>The array is NOT online!</p>";
    return;
}

if (!file_exists(ABSettings::$dockerIniFile)) {
    echo "<h1>Oooopsie!</h1><p>The docker config could not be found!</p>";
    return;
}

?>

<div class="title"><span class="left"><i class="fa fa-rotate-left title"></i>Restore</span></div>
<p>On this page, you are able to restore a previous made backup.</p>
<p>The restore process is able to:</p>
<ul>
    <li>Restore Container data</li>
    <li>Restore container template xml</li>
    <li>Restore extra files</li>
    <li>Restore backup configuration</li>
</ul>
<p><b>You have to create the docker containers by its restored template yourself!</b></p>

<form id="restoreForm">

    <div class="title"><span class="left"><i class="fa fa-folder title"></i>Step 1: Select source</span></div>
    <dt><b>Backup source:</b></dt>
    <dd><input type='text' required class='ftAttach' id="restoreSource" name="restoreSource"
               data-pickfilter="HIDE_FILES_FILTER" data-pickfolders="true">
        <button onclick="checkRestoreSource(); return false;">Check</button>
    </dd>

    <dt><b>Backup destination:</b><br/><small>Only applicable for container appdata</small></dt>
    <dd><?= $dockerCfg['DOCKER_APP_CONFIG_PATH'] ?><br/><small>The source share is being read from the docker default
            appdata path. If it does not seem correct, <a href="/Settings/DockerSettings">change it</a></small></dd>

    <div id="restoreBackupDiv" style="display: none">
        <div class="title"><span class="left"><i class="fa fa-folder title"></i>Step 2: Select backup</span></div>
        <dt><b>Select backup:</b></dt>
        <dd><select required id="restoreBackupList" name="restoreBackupList"></select>
            <button onclick="checkRestoreItem(); return false;">Next</button>
        </dd>
    </div>

    <div id="restoreItemsDiv" style="display: none">
        <div class="title"><span class="left"><i class="fa fa-folder title"></i>Step 3: Select items</span></div>
        <p><b>Note:</b> If one item is not selectable, the chosen backup does not contain needed data.</p>

        <dt><b>Restore backup config?:</b></dt>
        <dd><input type="checkbox" id="restoreItemConfig" name="restoreItem[config]"> Yes</dd>

        <dt><b>Restore extra files?:</b><br/><small><b>CAUTION!</b> The files will be restored on the ORIGINAL
                paths!</small></dt>
        <dd><input type="checkbox" id="restoreItemExtraFiles" name="restoreItem[extraFiles]"> Yes</dd>
        <br/>
        <dt><b>Restore VM meta?:</b><br/><small><b>CAUTION!</b> Restore will override /etc/libvirt/qemu contents!
                Restart VM manager after restore!</small></dt>
        <dd><input type="checkbox" id="restoreItemVmMeta" name="restoreItem[vmMeta]"> Yes</dd>
        <br/>
        <dt><b>Restore templates?:</b></dt>
        <dd>
            <div style="display: table;" id="restoreTemplatesDD"></div>
        </dd>

        <dt><b>Restore containers?:</b></dt>
        <dd>
            <div style="display: table;" id="restoreContainersDD"></div>
        </dd>

        <button onclick="startRestore(); return false;">Do it!</button>
    </div>

</form>

<script>
    function checkRestoreSource() {
        $('#restoreItemsDiv, #restoreItemsDiv').hide();
        $.ajax(url, {
            data: {action: 'checkRestoreSource', src: $('#restoreSource').val()}
        }).done(function (data) {
            if (data.result) {
                $('#restoreBackupList').html('');
                $('#restoreBackupDiv').show();
                $.each(data.result, function (i) {
                    console.log(data.result[i]);
                    var name = data.result[i]['name'];
                    $('#restoreBackupList').append('<option value="' + data.result[i]['path'] + '">' + name + '</option>');
                });
            } else {
                $('#restoreBackupDiv').hide();
                swal({
                    title: "Invalid source",
                    text: "The selected source seems invalid.",
                    type: "error",
                    confirmButtonText: "Ok"
                });
            }
        });
    }


    function checkRestoreItem() {
        $.ajax(url, {
            data: {action: 'checkRestoreItem', item: $('#restoreBackupList option:selected').val()}
        }).done(function (data) {
            if (data.result) {

                $('#restoreTemplatesDD, #restoreContainersDD').html('None available :(');

                $('#restoreItemsDiv').show();
                if (!data.result.configFile) {
                    $('#restoreItemConfig').attr('disabled', 'disabled');
                    $('#restoreItemConfig').prop('checked', false);
                } else {
                    $('#restoreItemConfig').removeAttr('disabled');
                    $('#restoreItemConfig').prop('checked', false);
                }

                if (!data.result.extraFiles) {
                    $('#restoreItemExtraFiles').attr('disabled', 'disabled');
                    $('#restoreItemExtraFiles').prop('checked', false);
                } else {
                    $('#restoreItemExtraFiles').removeAttr('disabled');
                    $('#restoreItemExtraFiles').prop('checked', false);
                }

                if (!data.result.vmMeta) {
                    $('#restoreItemVmMeta').attr('disabled', 'disabled');
                    $('#restoreItemVmMeta').prop('checked', false);
                } else {
                    $('#restoreItemVmMeta').removeAttr('disabled');
                    $('#restoreItemVmMeta').prop('checked', false);
                }

                if (data.result.templateFiles) {
                    $('#restoreTemplatesDD').html('');
                    $.each(data.result.templateFiles, function (i) {
                        $('#restoreTemplatesDD').append('<input type="checkbox" name="restoreItem[templates][' + data.result.templateFiles[i] + ']" /> ' + data.result.templateFiles[i] + '<br />');
                    });
                }

                if (data.result.containers) {
                    $('#restoreContainersDD').html('');
                    $.each(data.result.containers, function (i) {
                        $('#restoreContainersDD').append('<input type="checkbox" name="restoreItem[containers][' + data.result.containers[i] + ']" /> ' + data.result.containers[i] + '<br />');
                    });
                }

            } else {
                $('#restoreItemsDiv').hide();
                swal({
                    title: "Invalid backup",
                    text: "The selected backup seems invalid.",
                    type: "error",
                    confirmButtonText: "Ok"
                });
            }
        });
    }

    function startRestore() {
        $.ajax(url, {
            data: $('#restoreForm').serialize() + '&action=startRestore'
        }).always(function () {
            $('#tab3').click();
        });
    }
</script>