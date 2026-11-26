<?php

namespace unraid\plugins\AppdataBackup;

/** A schedule's cron line: the time fields each frequency writes, the values the tabs accept in them, and the cron file both schedules share */
class ABCron {

    /** The time fields of a schedule's frequency the last new ABSettings() found holding a time the tabs do not accept (see warning()); they keep their saved values */
    public static array $badTimes = [];

    /** Each frequency's cron time, {Field} standing for that time field, so it says which fields the frequency uses, here and on the tabs (ABStatus::nextRun reads them on its own); the values each field takes (the lists offer them without leading zeros); the labels the tabs show, in their order */
    private const LINES = ['daily' => '{Minute} {Hour} * * *', 'weekly' => '{Minute} {Hour} * * {Weekday}', 'monthly' => '{Minute} {Hour} {DayOfMonth} * *', 'custom' => '{Custom}'];
    private const RANGES = ['Hour' => [0, 23], 'Minute' => [0, 59], 'Weekday' => [0, 6], 'DayOfMonth' => [1, 31]];
    private const LABELS = ['Weekday' => 'Day of Week', 'DayOfMonth' => 'Day of Month', 'Hour' => 'Hour', 'Minute' => 'Minute', 'Custom' => 'Custom Cron Entry'];

    /** The time fields each frequency uses, in the tabs' order, for check() and the tabs' script */
    public static function fields() {
        return array_map(fn($line) => array_values(array_filter(array_keys(self::LABELS), fn($field) => str_contains($line, '{' . $field . '}'))), self::LINES);
    }
    /** The Custom Cron Entry the tabs accept, for preg_match and their RegExp alike, as dcron 4.5 runs it: five fields in range (names, lower case or capitalised, only for month and day of week; a step only after * or a range, and within dcron's int), or an @ word (@hourly to @yearly need an ID=); then ID= (no /: dcron names a timestamp file after it), AFTER= and FREQ= once each (FREQ= and an AFTER= wait above 0 in d, h, m or w, and within dcron's int; FREQ= on five fields with an ID= only) */
    public static function custom() {
        $count  = '0*[1-9][0-9]{0,8}'; // a step above 0 that dcron's int holds, as strtol reads it: 05 too
        $period = '0*([1-9][0-9]{0,6}m|[1-9][0-9]{0,4}h|[1-9][0-9]{0,3}d|[1-9][0-9]{0,2}w)'; // dcron's int seconds hold 7 digits of m, 5 of h, 4 of d, 3 of w
        $job    = '[^\s,/]+(/' . $period . ')?';
        $item   = fn($value, $not = '') => "$not((\\*|($value)-($value))(/$count)?|($value))";
        $fields = [['0?[0-9]|[1-5][0-9]'], ['0?[0-9]|1[0-9]|2[0-3]'], ['0?[1-9]|[12][0-9]|3[01]'], ['0?[1-9]|1[0-2]|[jJ]an|[fF]eb|[mM]ar|[aA]pr|[mM]ay|[jJ]un|[jJ]ul|[aA]ug|[sS]ep|[oO]ct|[nN]ov|[dD]ec'],
            ['0?[0-7]|[sS]un|[mM]on|[tT]ue|[wW]ed|[tT]hu|[fF]ri|[sS]at', '(?!(0?0|[sS]un)-0?7(?![0-9]))']]; // dcron runs 0-7 and sun-7 on Sunday only
        $time   = implode('[ \t]+', array_map(fn($field) => $item(...$field) . '(,' . $item(...$field) . ')*', $fields));
        return '^(?!(.*[ \t]ID=){2}|(.*[ \t]AFTER=){2}|(.*[ \t]FREQ=){2})(?=.*[ \t]ID=\S|(?!.*[ \t]FREQ=))(@(reboot|noauto|(hourly|daily|weekly|monthly|yearly)(?=.*[ \t]ID=\S))(?!.*[ \t]FREQ=)|' . $time . ')([ \t]+(ID=[^\s/]+|AFTER=' . $job . '(,' . $job . ')*|FREQ=' . $period . '(/' . $period . ')?))*$';
    }

    /** Lists in $badTimes each time field a schedule's frequency writes (as time() does) that accepts() rejects: write() then keeps that schedule's cron line */
    public static function check(ABSettings $settings) {
        self::$badTimes = [];
        foreach (['backupFrequency', ...($settings->extraSchedule === 'yes' ? ['extraFrequency'] : [])] as $prefix) {
            foreach (self::fields()[$settings->$prefix] ?? [] as $field) {
                if (!in_array($prefix . $field, ABSettings::$unreadable, true) && !self::accepts($field, $settings->{$prefix . $field})) { // load() named it already
                    self::$badTimes[] = $prefix . $field;
                }
            }
        }
    }

    /** Whether a time field holds a value its tab offers (Hour and Minute: a whole number in range, "05" too), or a Custom Cron Entry custom() accepts */
    public static function accepts(string $field, string|int $value) {
        if ($field === 'Custom') {
            return (bool)preg_match('~' . self::custom() . '~D', trim((string)$value));
        }
        [$min, $max] = self::RANGES[$field];
        $digits      = (string)$value;
        return ctype_digit($digits) && (int)$digits >= $min && (int)$digits <= $max && (in_array($field, ['Hour', 'Minute'], true) || $digits === (string)(int)$digits);
    }

    /** The warning about $badTimes, for the Settings tab and checkCron.php; '' when every time field in use holds one the tabs accept */
    public static function warning() {
        $where = ABSettings::byTab(self::$badTimes, fn($item) => self::LABELS[preg_replace('/^(backup|extra)Frequency/', '', $item)]);
        return $where !== '' ? 'These schedule fields hold a time this page does not accept, so their schedule stays as it was until you fix them and Save on ' . $where . '.' : '';
    }

    /** Whether write() keeps a schedule's cron line as it is, even none: one of its time fields holds a time the tabs do not accept, or a setting of it could not be read (a default could start, stop or move it) */
    public static function held(bool $extra) {
        $pattern = $extra ? '/^(extraFrequency|extraSchedule$)/' : '/^backupFrequency/';
        return ABSettings::unread($pattern) || preg_grep($pattern, self::$badTimes);
    }

    /** The cron time fields for the schedule whose settings start with $prefix, or '' when it is off */
    private static function time(ABSettings $settings, string $prefix) {
        return preg_replace_callback('/\{(\w+)\}/', fn($field) => trim((string)$settings->{$prefix . $field[1]}), self::LINES[$settings->$prefix] ?? '');
    }

    /** One schedule's lines in the cron file's $lines */
    private static function savedLines(array $lines, bool $extra) {
        return array_values(array_filter($lines, fn($line) => str_contains($line, '/scripts/backup.php') && str_contains($line, ' extra ') === $extra)); // older versions wrote the main line without 'scheduled'
    }

    /** Writes both schedules' lines to the cron file and has dcron read it: [update_cron's exit code, its output], or [1, why] when the file could not be read or written */
    public static function write(ABSettings $settings) {
        $cronPath = ABSettings::$pluginDir . '/' . ABSettings::$cronFile;
        $saved    = is_file($cronPath) ? @file($cronPath, FILE_IGNORE_NEW_LINES) : []; // read once, so both schedules keep lines of the same file
        $lines    = [];
        // 'scheduled' makes a run wait for a running job instead of being refused
        foreach (['backupFrequency' => 'scheduled', 'extraFrequency' => 'scheduled extra'] as $prefix => $args) {
            $extra = $prefix === 'extraFrequency';
            if (self::held($extra)) {
                if ($saved === false) {
                    return [1, ["The cron file $cronPath could not be read, so the schedules were left as they were"]];
                }
                array_push($lines, ...self::savedLines($saved, $extra));
                continue;
            }
            $time = $extra && $settings->extraSchedule !== 'yes' ? '' : self::time($settings, $prefix);
            if ($time !== '') {
                $lines[] = $time . ' php ' . dirname(__DIR__) . '/scripts/backup.php ' . $args . ' > /dev/null 2>&1';
            }
        }

        $written = $lines ? ABSettings::replaceFile($cronPath, '# Appdata.Backup cron settings' . PHP_EOL . implode(PHP_EOL, $lines) . PHP_EOL) : !file_exists($cronPath) || @unlink($cronPath);
        if (!$written) {
            return [1, ["The cron file $cronPath could not be written, so the schedules may not match these settings"]];
        }
        // Let dcron know our changes via update_cron
        $out = $code = 0;
        exec("update_cron", $out, $code);
        return [$code, $out];
    }

}
