<?php

namespace unraid\plugins\AppdataBackup;

require_once __DIR__ . '/ABHelper.php';

/** The steps of a backup run: numbered headings in the log, and the line under "The backup is running" on the Status / Log page */
class ABSteps {

    /** This run's steps in order, from plan(), and the one in progress */
    private static array $steps = [];
    private static string $step = '';

    /** Plans this run's steps from the settings, so start() can number them */
    public static function plan(ABSettings $settings) {
        $stopAll     = $settings->backupMethod == 'stopAll';
        $snapshot    = $stopAll && $settings->snapshotMode == 'yes';
        self::$steps = array_values(array_filter([
            'Preparing',
            $stopAll ? 'Stopping containers' : null,
            $snapshot ? 'Starting containers' : null,
            'Backing up containers',
            $stopAll && !$snapshot ? 'Starting containers' : null,
            $settings->flashBackup == 'yes' ? 'Backing up the flash drive' : null,
            $settings->backupVMMeta == 'yes' ? 'Backing up VM meta' : null,
            $settings->includeFiles ? 'Backing up extra files' : null,
            'Writing checksums',
            'Checking retention',
            'Finishing',
        ]));
    }

    public static function start($label) {
        self::$step = $label;
        ABHelper::backupLog(self::text());
        self::detail('');
    }

    /** Adds what the step is working on, e.g. "lidarr, 12 of 33", to the line on the Status / Log page */
    public static function detail($detail) {
        file_put_contents(ABSettings::$tempFolder . '/' . ABSettings::$stateFileStep, self::text() . ($detail === '' ? '' : " ($detail)"));
    }

    /** Stop-all without a snapshot starts the containers after the backup, so that step moves behind it */
    public static function startContainersLast() {
        $steps = array_values(array_diff(self::$steps, ['Starting containers']));
        array_splice($steps, (int)array_search('Backing up containers', $steps) + 1, 0, ['Starting containers']);
        self::$steps = $steps;
    }

    private static function text() {
        $n = array_search(self::$step, self::$steps, true);
        return ($n === false ? '' : 'Step ' . ($n + 1) . '/' . count(self::$steps) . ': ') . self::$step;
    }
}
