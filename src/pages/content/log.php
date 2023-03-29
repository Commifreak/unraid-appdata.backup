<?php

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
?>

<style>
    .backupRunning {
        color: green;
    }

    .backupRunning:after {
        content: 'running';
    }

    .backupNotRunning {
        color: red;
    }

    .backupNotRunning:after {
        content: 'not running';
    }
</style>

<h3>The backup is <span id="backupStatusText" class=""></span>.</h3>
<div style='border: 1px solid red; height:500px; overflow:auto' id='abLog'>Loading...</div>
<input type='button' id="abortBtn" value='Abort' disabled/>
<input type='button' id="dlLogBtn" value='Download log' disabled/>
<input type='button' id="dlDbgLogBtn" value='Download debug log' disabled/>


<script>
    let url = "/plugins/<?= ABSettings::$appName ?>/include/http.php";

    $(function () {
        setInterval(function () {
            checkBackup();
        }, 1000);

        $('#abortBtn').on('click', function () {
            swal({
                title: "Proceed?",
                text: "Are you sure you want to abort?",
                type: 'warning',
                html: true,
                showCancelButton: true,
                confirmButtonText: "Yep",
                cancelButtonText: "Nah"
            }, function () {
                $.ajax(url, {
                    data: {action: 'abort'}
                });
            });
        });

        $('#dlLogBtn, #dlDbgLogBtn').on('click', function () {
            window.location = url + '?action=dlLog&type=' + $(this).attr('id');
        });
    });

    function checkBackup() {
        $.ajax(url,
            {
                data: {action: 'getBackupState'}
            }).done(function (data) {
            if (data.running) {
                $('#abortBtn').prop('disabled', false);
                $('#dlLogBtn, #dlDbgLogBtn').prop('disabled', true);
                $('#backupStatusText').removeClass('backupNotRunning');
                $('#backupStatusText').addClass('backupRunning');
                $('#abLog').animate({
                    scrollTop: $('#abLog')[0].scrollHeight - $('#abLog')[0].clientHeight
                }, 100);
            } else {
                $('#abortBtn').prop('disabled', true);
                $('#dlLogBtn, #dlDbgLogBtn').prop('disabled', false);
                $('#backupStatusText').removeClass('backupRunning');
                $('#backupStatusText').addClass('backupNotRunning');
            }
            if (data.log == "") {
                $("#abLog").html("The log is not existing or empty");
            } else {
                $("#abLog").html(data.log);
            }
        }).fail(function () {
            $("#abLog").html("Something went wrong while talking to the backend :(");
        });
    }
</script>