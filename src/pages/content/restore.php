<?php

use unraid\plugins\AppdataBackup\ABHelper;
use unraid\plugins\AppdataBackup\ABSettings;

/** @var $abSettings ABSettings */

if (!ABHelper::isArrayOnline()) {
    echo "<h1>Oooopsie!</h1><p>The array is NOT online!</p>";
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
<br/>
<p>The restore process <b>is NOT able to</b>:</p>
<ul>
    <li>Create your docker containers</li>
    <li>Take care of stopping containers prior restore
        <ul>
            <li>Please stop all maybe affected containers yourself prior to the restore!</li>
        </ul>
    </li>
</ul>

<form id="restoreForm">

    <div class="title"><span class="left"><i class="fa fa-folder title"></i>Step 1: Select source</span></div>
    <dl>
        <dt><b>Backup source:</b></dt>
        <dd><input type='text' required class='ftAttach' id="restoreSource" name="restoreSource"
                   value="<?= empty($abSettings->destination) ? '' : $abSettings->destination ?>"
                   data-pickfilter="HIDE_FILES_FILTER" data-pickfolders="true">
        </dd>
    </dl>

    <blockquote class='inline_help'>
        <p>The folder which contains <code>ab_xxx</code> folders.</p>
    </blockquote>


    <dl>
        <dt><b>Backup destination:</b></dt>
        <dd>
            <div style="display: table">The <b>default</b> destination will be the same as it were during backup. If the
                destination does not exist, it will be
                created. Any existing data will be overwritten!<br/>
                <b>If you want to force a custom destination</b>, enter it below. The archive will be extracted
                there<br/>
                <b>THIS IS ONLY APPLICABLE TO ARCHIVES!</b><br/>
                <input type='text' class='ftAttach' id="customRestoreDestination" name="customRestoreDestination"
                       placeholder="Force custom destination"
                       data-pickfilter="HIDE_FILES_FILTER" data-pickfolders="true"><br/><br/>
                <button onclick="checkRestoreSource(); return false;">Next</button>
            </div>
        </dd>
    </dl>


    <div id="restoreBackupDiv" style="display: none">
        <div class="title"><span class="left"><i class="fa fa-folder title"></i>Step 2: Select backup</span></div>
        <dl>
            <dt><b>Select backup:</b></dt>
            <dd><select required id="restoreBackupList" name="restoreBackupList"></select>
                <button onclick="checkRestoreItem(); return false;">Next</button>
            </dd>
        </dl>
    </div>

    <div id="restoreItemsDiv" style="display: none">
        <div class="title"><span class="left"><i class="fa fa-folder title"></i>Step 3: Select items</span></div>
        <p><b>Note:</b> If one item is not selectable, the chosen backup does not contain needed data.</p>

        <dl>
            <dt><b>Restore backup config?:</b></dt>
            <dd><input type="checkbox" id="restoreItemConfig" name="restoreItem[config]"> Yes</dd>

            <dt><b>Restore extra files?:</b></dt>
            <dd><input type="checkbox" id="restoreItemExtraFiles" name="restoreItem[extraFiles]"> Yes</dd>
            <br/>
            <dt><b>Restore VM meta?:</b></dt>
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
        </dl>

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
                    $('#restoreItemConfig').prop('disabled', true);
                    $('#restoreItemConfig').prop('checked', false);
                } else {
                    $('#restoreItemConfig').prop('disabled', false);
                    $('#restoreItemConfig').prop('checked', false);
                }

                if (!data.result.extraFiles) {
                    $('#restoreItemExtraFiles').prop('disabled', true);
                    $('#restoreItemExtraFiles').prop('checked', false);
                } else {
                    $('#restoreItemExtraFiles').prop('disabled', false);
                    $('#restoreItemExtraFiles').prop('checked', false);
                }

                if (!data.result.vmMeta) {
                    $('#restoreItemVmMeta').prop('disabled', true);
                    $('#restoreItemVmMeta').prop('checked', false);
                } else {
                    $('#restoreItemVmMeta').prop('disabled', false);
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