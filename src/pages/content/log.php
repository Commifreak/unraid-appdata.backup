<?php

use unraid\plugins\AppdataBackup\ABHelper;
use unraid\plugins\AppdataBackup\ABSettings;
use unraid\plugins\AppdataBackup\ABStatus;

if (!ABHelper::isArrayOnline()) {
    echo "<h1>Oooopsie!</h1><p>The array is NOT online!</p>";
    return;
}

require_once dirname(__DIR__, 2) . '/include/ABStatus.php';
include_once __DIR__ . '/head.php';

$abSettings = $abSettings ?? new ABSettings();
$summary    = ABStatus::summary($abSettings);
$date       = fn($set) => $set['date']->format('d.m.Y H:i');

if ($summary['latest']) {
    $state = ['ok' => 'OK', 'failed' => 'failed', 'incomplete' => 'incomplete'][$summary['latest']['state']];
    $rows['Last backup'] = $date($summary['latest']) . ' &middot; ' . $state . ($summary['duration'] !== null ? ' &middot; took ' . ABStatus::minutes($summary['duration']) : '');
} else {
    $rows['Last backup'] = $summary['recorded'] ? 'None yet' : 'Shown after the next backup run';
}
if ($summary['recorded']) {
    $rows['Backup sets'] = count($summary['ok']) . ' good (' . ABStatus::bytes($summary['okSize']) . ')'
        . ($summary['failed'] ? ', ' . count($summary['failed']) . ' failed (' . ABStatus::bytes($summary['failedSize']) . ')' : '')
        . ($summary['ok'] ? ' &middot; newest ' . $date($summary['ok'][0]) . ', oldest ' . $date($summary['ok'][count($summary['ok']) - 1]) : '')
        . ' &middot; as of the last backup run';
}
$rows['Next scheduled run'] = $summary['next'] ? $summary['next']->format('D d.m.Y H:i') : ($abSettings->backupFrequency === 'custom' ? 'Custom: ' . htmlspecialchars($abSettings->backupFrequencyCustom) : 'Not scheduled');
$rows['Free space'] = $summary['free'] === false ? 'Unknown' : ABStatus::bytes($summary['free']) . ' free' . ($summary['ok'] ? ' &middot; newest backup ' . ABStatus::bytes($summary['ok'][0]['size']) : '');

$warnings = [];
if ($summary['stale'] !== false) {
    $warnings[] = $summary['stale'] === null ? 'No successful backup yet.' : 'No successful backup for ' . $summary['stale'] . ' days (schedule: ' . $abSettings->backupFrequency . ').';
}
if ($summary['lowSpace']) {
    $warnings[] = 'Free space (' . ABStatus::bytes($summary['free']) . ') is less than the newest backup (' . ABStatus::bytes($summary['ok'][0]['size']) . ').';
}

?>

<div class="ab-status">
    <dl class="ab-grid">
<?php foreach ($rows as $label => $value): ?>
        <dt><?= $label ?></dt><dd><?= $value ?></dd>
<?php endforeach; ?>
    </dl>
<?php foreach ($warnings as $warning): ?>
    <p class="ab-warn"><?= $warning ?></p>
<?php endforeach; ?>
</div>

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
<br/>
You are currently viewing the <b id="currentLogType">normal</b> log!
<br/>
<div class='ab-log' id='abLog'>Loading...</div>
<input type='button' id="abortBtn" value='Abort' disabled/>
<input type='button' id="switchLog" data-log-type="normal" value='Switch log'/>


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