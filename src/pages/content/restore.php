<?php

use unraid\plugins\AppdataBackup\ABHelper;
use unraid\plugins\AppdataBackup\ABSettings;

/** @var $abSettings ABSettings */

if (!ABHelper::isArrayOnline()) {
    echo "<h1>Oooopsie!</h1><p>The array is NOT online!</p>";
    return;
}


?>
<?php include_once __DIR__ . '/head.php'; ?>

<div class="title"><span class="left"><i class="fa fa-rotate-left title"></i>Restore</span></div>
<p>On this page, you are able to restore a previously made backup.</p>
<p>The restore process is able to:</p>
<ul>
    <li>Restore container data</li>
    <li>Restore container template XML</li>
    <li>Restore extra files</li>
    <li>Restore backup configuration</li>
</ul>
<br/>
<p>The restore process <b>is NOT able to</b>:</p>
<ul>
    <li>Create your docker containers</li>
    <li>Take care of stopping containers prior to the restore
        <ul>
            <li>Please stop all potentially affected containers yourself prior to the restore!</li>
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
        <p>The folder that holds your <code>ab_…</code> backup sets, normally your <b>Backup destination</b>.</p>
    </blockquote>


    <dl>
        <dt><b>Backup destination:</b></dt>
        <dd>
            <div style="display: table"><b>Any existing data will be overwritten!</b><br/>
                <input type='text' class='ftAttach' id="customRestoreDestination" name="customRestoreDestination"
                       placeholder="Force custom destination"
                       data-pickfilter="HIDE_FILES_FILTER" data-pickfolders="true"><br/><br/>
                <button onclick="checkRestoreSource(); return false;">Next</button>
            </div>
        </dd>
    </dl>
    <blockquote class='inline_help'>
        <p>Leave empty to restore everything to where it was backed up from. A folder here extracts the archives into it instead; templates and plugin settings still go to their usual place.</p>
    </blockquote>


    <div id="restoreBackupDiv" style="display: none">
        <div class="title"><span class="left"><i class="fa fa-folder title"></i>Step 2: Select backup</span></div>
        <dl>
            <dt><b>Select backup:</b></dt>
            <dd><div class="ab-inline"><select required id="restoreBackupList" name="restoreBackupList" onchange="verifyAvailability();"></select>
                <button onclick="checkRestoreItem(); return false;">Next</button>
                <button id="verifySetBtn" class="ab-job" onclick="verifySet(); return false;" title="Re-reads every file in this set and compares it with the checksum written at backup time. Runs in the background; the result shows on Status / Log.">Verify checksums</button><small class="ab-reason"></small></div>
            </dd>
        </dl>
        <blockquote class='inline_help'>
            <p>The backup set to restore from. <b>Verify checksums</b> checks it first: every file is re-read and compared with the checksum written at backup time.</p>
        </blockquote>
    </div>

    <div id="restoreItemsDiv" style="display: none">
        <div class="title"><span class="left"><i class="fa fa-folder title"></i>Step 3: Select items</span></div>
        <p><b>Note:</b> If one item is not selectable, the chosen backup does not contain needed data.</p>

        <dl>
            <dt><b>Restore backup config?:</b></dt>
            <dd><label class="ab-check"><input type="checkbox" id="restoreItemConfig" name="restoreItem[config]"> <span>Yes</span></label></dd>
        </dl>
        <blockquote class='inline_help'>
            <p>Replaces this plugin's current settings with the ones saved in the backup.</p>
        </blockquote>

        <dl>
            <dt><b>Restore extra files?:</b></dt>
            <dd><label class="ab-check"><input type="checkbox" id="restoreItemExtraFiles" name="restoreItem[extraFiles]"> <span>Yes</span></label></dd>
        </dl>
        <blockquote class='inline_help'>
            <p>Puts the files from "Include extra files/folders" back where they came from, or into the custom destination.</p>
        </blockquote>

        <dl>
            <dt><b>Restore VM meta?:</b></dt>
            <dd><label class="ab-check"><input type="checkbox" id="restoreItemVmMeta" name="restoreItem[vmMeta]"> <span>Yes</span></label></dd>
        </dl>
        <blockquote class='inline_help'>
            <p>Puts the VM definitions from <code>/etc/libvirt/qemu</code> back. The VM manager must be enabled.</p>
        </blockquote>

        <div class="ab-restore-lists">
            <div class="ab-restore-list">
                <dl class="ab-restore-list-head"><dt><b>Restore templates</b></dt><dd><span class="ab-pick"><a href="#" data-target="restoreTemplatesDD" data-checked="1">All</a> / <a href="#" data-target="restoreTemplatesDD" data-checked="0">None</a></span></dd></dl>
                <blockquote class='inline_help'><p>Copies the selected Docker templates back, so the containers can be added again with their saved settings.</p></blockquote>
                <div class="ab-checklist" id="restoreTemplatesDD"></div>
            </div>
            <div class="ab-restore-list">
                <dl class="ab-restore-list-head"><dt><b>Restore containers</b></dt><dd><span class="ab-pick"><a href="#" data-target="restoreContainersDD" data-checked="1">All</a> / <a href="#" data-target="restoreContainersDD" data-checked="0">None</a></span></dd></dl>
                <blockquote class='inline_help'><p>Extracts each container's data back where it came from, or into the custom destination. Existing files are overwritten, so stop the containers first.</p></blockquote>
                <div class="ab-checklist" id="restoreContainersDD"></div>
            </div>
        </div>

        <button class="ab-job" onclick="startRestore(); return false;" title="Starts the restore in the background; follow it on Status / Log.">Do it!</button><small class="ab-reason"></small>
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
                    $('#restoreBackupList').append('<option value="' + data.result[i]['path'] + '" data-checksums="' + (data.result[i]['checksums'] ? '1' : '') + '">' + name + '</option>');
                });
                verifyAvailability();
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


    // Names come from the backup folder: attr() and a text node keep them from being parsed as HTML
    function restoreCheck(kind, name) {
        return $('<label class="ab-check">').attr('title', name)
            .append($('<input type="checkbox">').attr('name', 'restoreItem[' + kind + '][' + name + ']'), ' ', document.createTextNode(name));
    }

    function checkRestoreItem() {
        $.ajax(url, {
            data: {action: 'checkRestoreItem', item: $('#restoreBackupList option:selected').val()}
        }).done(function (data) {
            if (data.result) {

                $('#restoreTemplatesDD, #restoreContainersDD').html('None available :(');

                $('#restoreItemsDiv').show();
                setRestoreItem('restoreItemConfig', data.result.configFile);

                setRestoreItem('restoreItemExtraFiles', data.result.extraFiles);

                setRestoreItem('restoreItemVmMeta', data.result.vmMeta);

                if (data.result.templateFiles) {
                    $('#restoreTemplatesDD').html('');
                    $.each(data.result.templateFiles.sort(byName), function (i, name) {
                        $('#restoreTemplatesDD').append(restoreCheck('templates', name));
                    });
                }

                if (data.result.containers) {
                    $('#restoreContainersDD').html('');
                    $.each(data.result.containers.sort(byName), function (i, name) {
                        $('#restoreContainersDD').append(restoreCheck('containers', name));
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

    function verifyAvailability() {
        $('#verifySetBtn').attr('data-blocked', $('#restoreBackupList option:selected').attr('data-checksums') === '1' ? '' : 'No checksums in this set');
        abLockButtons();
    }

    function verifySet() {
        $.ajax(url, {
            type: 'POST',
            data: {action: 'verifySet', set: $('#restoreBackupList').val()}
        }).always(function () {
            $('#tab3').click();
        });
    }

    const byName = (a, b) => a.localeCompare(b, undefined, {numeric: true, sensitivity: 'base'});

    $(document).on('click', '.ab-pick a', function (e) {
        e.preventDefault();
        $('#' + $(this).data('target') + ' input:not(:disabled)').prop('checked', $(this).data('checked') == 1);
    });

    function setRestoreItem(id, available) {
        $('#' + id).prop('disabled', !available).prop('checked', false).next('span').text(available ? 'Yes' : 'Not in this backup');
    }

    function startRestore() {
        $.ajax(url, {
            type: 'POST',
            data: $('#restoreForm').serialize() + '&action=startRestore'
        }).always(function () {
            $('#tab3').click();
        });
    }
</script>