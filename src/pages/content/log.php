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
<div style='border: 1px solid red; height:500px; overflow:auto' id='abLog'>Loading...</div>
<input type='button' id="abortBtn" value='Abort' disabled/>
<input type='button' id="shareDbgLogBtn" value='Share debug log' disabled/>
<p id="didContainer" style="display: none; width: 200px;">Your debug log ID: <input type="text" id="did"
                                                                                    onmouseover="$(this).select()"/></p>


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

        $('#shareDbgLogBtn').on('click', function () {
            swal({
                title: "Share debug log?",
                text: "With this function, you can share the detailed backup log with the developer (and only the developer) for diagnostic purposes!<br />This will send the log via a secure connection to the developers server.<br />You will receive a unique debug log ID and share it publicly without its sensitive contents.<br /><br />You can also find the log at <code><?= ABSettings::$tempFolder ?></code>",
                type: 'warning',
                html: true,
                showCancelButton: true,
                confirmButtonText: "Share",
                cancelButtonText: "Abort"
            }, function () {
                shareLog();
            });
        });
    });

    function checkBackup() {
        $.ajax(url,
            {
                data: {action: 'getBackupState'}
            }).done(function (data) {
            console.log(data);
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

    function shareLog() {
        $.ajax(url, {
            data: {action: 'shareLog'}
        }).fail(function (data) {
            $('#did').val('Error during HTTP request!');
        }).done(function (data) {
            $('#did').val(data.msg);
        }).always(function () {
            $('#didContainer').css('display', 'inline');
        });
    }
</script>