<?php

use unraid\plugins\AppdataBackup\ABHelper;
use unraid\plugins\AppdataBackup\ABSettings;

if (!ABHelper::isArrayOnline()) {
    echo "<h1>Oooopsie!</h1><p>The array is NOT online!</p>";
    return;
}

include_once __DIR__ . '/head.php';

$abRunningAtRender = ABHelper::scriptRunning();

?>

<div id="abStatus"><?php include __DIR__ . '/status.php'; ?></div>

<style>
    .backupRunning {
        color: green;
    }

    .backupNotRunning {
        color: red;
    }
</style>

<h3 id="abJobStatus"></h3>
<p id="abStep" class="ab-step"></p>
<span>You can find the normal log at: <code><?= ABSettings::$tempFolder . '/' . ABSettings::$logfile; ?></code></span>
<br/>
<span>You can find the debug &nbsp;log at: <code><?= ABSettings::$tempFolder . '/' . ABSettings::$debugLogFile; ?></code></span>
<br/>
<br/>
You are currently viewing the <b id="currentLogType">normal</b> log!
<br/>
<div class='ab-log' id='abLog'>Loading...</div>
<input type='button' id="abortBtn" value='Abort' title="Stops the running job right away. An aborted backup is marked failed, and containers it had stopped and not started yet stay stopped." disabled/>
<input type='button' id="switchLog" data-log-type="normal" value='Switch log'/>


<script>
    let url = "/plugins/<?= ABSettings::$appName ?>/include/http.php";
    let wasRunning = <?= $abRunningAtRender ? 'true' : 'false' ?>; // as when the box above was drawn
    let abBusy = ''; // the running job, from the last poll: it blocks every .ab-job button on all three tabs
    let abStartingUntil = 0; // a job start was just sent and the poll may not show it yet

    $(function () {
        checkBackup();
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
                    type: 'POST',
                    data: {action: 'abort'}
                });
            });
        });

        $('#switchLog').on('click', function () {
            let currentLogType = $('#switchLog').data('log-type');
            let newLogType = currentLogType === 'normal' ? 'debug' : 'normal'
            $('#switchLog').data('log-type', newLogType);
            $('#currentLogType').html(newLogType);
        });

    });

    function checkBackup() {
        $.ajax(url,
            {
                data: {action: 'getBackupState', logType: $('#switchLog').data('log-type')}
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
                $('#abJobStatus').attr('class', 'backupRunning').text(data.job + '.');
                $('#abLog').animate({
                    scrollTop: $('#abLog')[0].scrollHeight - $('#abLog')[0].clientHeight
                }, 100);
            } else {
                $('#abortBtn').prop('disabled', true);
                $('#shareDbgLogBtn').prop('disabled', false);
                $('#abJobStatus').attr('class', '').html('The backup is <span class="backupNotRunning">not running</span>.');
            }
            abBusy = data.running ? data.job : '';
            abLockButtons();

            $('#abStep').text(data.running ? (data.step || '') : '');
            // The run writes its summary just before it ends, so the box can be refreshed now
            if (wasRunning && !data.running) {
                $.get(url, {action: 'getStatus'}, function (status) {
                    $('#abStatus').html(status.html);
                });
            }
            wasRunning = !!data.running;
        }).fail(function () {
            $("#abLog").html("Something went wrong while talking to the backend :(");
        });
    }

    /** Greys out each .ab-job button while a job runs or starts, or its own data-blocked reason applies, with the reason beside it */
    function abLockButtons() {
        $('.ab-job').each(function () {
            const reason = abBusy || (Date.now() < abStartingUntil ? 'Starting…' : '') || $(this).attr('data-blocked') || '';
            let note = $(this).next('.ab-reason');
            if (!note.length) {
                note = $('<small class="ab-reason"></small>').insertAfter(this);
            }
            $(this).prop('disabled', reason !== '');
            note.text(reason);
        });
    }

    /** Sends a job start; the buttons stay locked until the poll shows the job, so a double click cannot send two */
    function abStartJob(data) {
        abStartingUntil = Date.now() + 5000;
        abLockButtons();
        $.ajax(url, {type: 'POST', data: data}).fail(function () {
            abStartingUntil = 0;
            abLockButtons();
        }).always(function () {
            $('#tab3').click();
        });
    }
</script>