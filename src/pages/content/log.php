<?php

use unraid\plugins\AppdataBackup\ABHelper;
use unraid\plugins\AppdataBackup\ABSettings;

if (!ABHelper::isArrayOnline()) {
    echo "<h1>Oooopsie!</h1><p>The array is NOT online!</p>";
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
<span>You can find the normal log at: <code><?= ABSettings::$tempFolder . '/' . ABSettings::$logfile; ?></code></span>
<br/>
<span>You can find the debug &nbsp;log at: <code><?= ABSettings::$tempFolder . '/' . ABSettings::$debugLogFile; ?></code></span>
<br/>
<div style='border: 1px solid red; height:500px; overflow:auto;' id='abLog'>Loading...</div>
<input type='button' id="abortBtn" value='Abort' disabled/>


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
    });

    function checkBackup() {
        $.ajax(url,
            {
                data: {action: 'getBackupState'}
            }).done(function (data) {

            if (data.log == "") {
                $("#abLog").html("The log is not existing or empty");
            } else {
                $("#abLog").html(data.log);
            }

            if (data.running) {
                $('#didContainer').css('display', 'none');
                $('#abortBtn').prop('disabled', false);
                $('#shareDbgLogBtn').prop('disabled', true);
                $('#backupStatusText').removeClass('backupNotRunning');
                $('#backupStatusText').addClass('backupRunning');
                $('#abLog').animate({
                    scrollTop: $('#abLog')[0].scrollHeight - $('#abLog')[0].clientHeight
                }, 100);
            } else {
                $('#abortBtn').prop('disabled', true);
                $('#shareDbgLogBtn').prop('disabled', false);
                $('#backupStatusText').removeClass('backupRunning');
                $('#backupStatusText').addClass('backupNotRunning');
            }
        }).fail(function () {
            $("#abLog").html("Something went wrong while talking to the backend :(");
        });
    }
</script>