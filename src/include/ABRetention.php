<?php

namespace unraid\plugins\AppdataBackup;

require_once __DIR__ . '/ABStatus.php';

/** Which backup sets a run's retention keeps: By age, or grandfather-father-son (GFS). backup.php deletes the rest. */
class ABRetention {

    /** GFS: each field, how a set's time names its period (ISO weeks, Monday first) in this server's time zone, and its word */
    const PERIODS = ['keepDaily' => ['Y-m-d', 'day'], 'keepWeekly' => ['o-W', 'week'], 'keepMonthly' => ['Y-m', 'month'], 'keepYearly' => ['Y', 'year']];

    /** The Retention choices: as saved => as the Settings tab shows them */
    const MODES = ['age' => 'By age', 'gfs' => 'Grandfather-father-son (GFS)'];

    /** Each field's label on the Settings tab; label() adds the Extra schedule tab's prefix */
    const LABELS = ['retentionMode' => 'Retention', 'deleteBackupsOlderThan' => 'Delete backups if older than x days', 'keepMinBackups' => 'Keep at least this many backups',
        'keepDaily' => 'Keep one backup a day for the last x days', 'keepWeekly' => 'Keep one backup a week for the last x weeks',
        'keepMonthly' => 'Keep one backup a month for the last x months', 'keepYearly' => 'Keep one backup a year for the last x years'];

    /** A field's label on the Settings tab, or on the Extra schedule tab */
    public static function label(string $field, bool $extra = false): string {
        return $extra ? 'Extra schedule: ' . lcfirst(self::LABELS[$field]) : self::LABELS[$field];
    }

    /** A field as retention reads it: '' is 0, a whole number from 0 to 999999 as a number field takes it (7, 7.0, 1e3) is itself, anything else null */
    public static function count($value): ?int {
        $value  = trim((string)$value);
        $number = $value === '' ? 0.0 : (is_numeric($value) ? (float)$value : -1.0);
        return $number >= 0 && $number <= 999999 && floor($number) === $number ? (int)$number : null;
    }

    /** Names the saved retention value these settings cannot read, so retention keeps every set, or '' */
    public static function unreadable(ABSettings $settings): string {
        $gfs   = $settings->retentionMode === 'gfs';
        $extra = $settings->schedule === 'extra'; // ABSettings::forSchedule
        if (!isset(self::MODES[$settings->retentionMode])) {
            return self::label('retentionMode', $extra) . ' is "' . $settings->retentionMode . '", not ' . implode(' or ', self::MODES);
        }
        foreach ($gfs ? array_keys(self::PERIODS) : ['deleteBackupsOlderThan', 'keepMinBackups'] as $field) {
            if (self::count($settings->$field) === null) {
                return self::label($field, $extra) . ' is "' . trim((string)$settings->$field) . '", not a whole number from 0 to 999999';
            }
        }
        return '';
    }

    /** Why these settings keep every set, or '' when retention deletes */
    public static function off(ABSettings $settings): string {
        if (($unreadable = self::unreadable($settings)) !== '') {
            return $unreadable;
        }
        $gfs   = $settings->retentionMode === 'gfs';
        $extra = $settings->schedule === 'extra';
        if ($gfs) {
            return array_filter(array_keys(self::PERIODS), fn($field) => self::count($settings->$field)) ? '' : ($extra ? 'the extra schedule\'s' : 'the') . ' four Grandfather-father-son fields are empty or 0';
        }
        return self::count($settings->deleteBackupsOlderThan) ? '' : self::label('deleteBackupsOlderThan', $extra) . ' is ' . (trim((string)$settings->deleteBackupsOlderThan) === '' ? 'empty' : '0');
    }

    /** The sets to keep (path => why), all of them while off() says why; $sets: this run's type, newest first; $good: those that finished without errors */
    public static function keep(ABSettings $settings, array $sets, array $good, \DateTime $now): array {
        if (($off = self::off($settings)) !== '') {
            return array_fill_keys($sets, "retention off ($off)");
        }
        $kept = [];
        if ($settings->retentionMode === 'gfs') {
            foreach (self::PERIODS as $field => [$format, $word]) {
                $periods = [];
                $count   = self::count($settings->$field);
                foreach ($good as $set) {
                    $period = date($format, ABStatus::setTime(basename($set)));
                    if (isset($periods[$period])) {
                        continue;
                    }
                    if (count($periods) === $count) {
                        break;
                    }
                    $periods[$period] = true;
                    $kept[$set][]     = "the newest of its $word ($period)";
                }
            }
            return array_map(fn($why) => implode(', ', $why), $kept);
        }
        $min = self::count($settings->keepMinBackups);
        foreach (array_slice($good, 0, $min) as $set) {
            $kept[$set] = "one of the newest $min good sets";
        }
        $days   = self::count($settings->deleteBackupsOlderThan);
        $cutoff = (clone $now)->modify("-$days days")->getTimestamp();
        foreach ($sets as $set) {
            if (!isset($kept[$set]) && ABStatus::setTime(basename($set)) >= $cutoff) {
                $kept[$set] = "newer than $days " . ($days === 1 ? 'day' : 'days');
            }
        }
        return $kept;
    }

    /** These settings' retention in a few words, for the logs */
    public static function describe(ABSettings $settings): string {
        if (($off = self::off($settings)) !== '') {
            return "retention off ($off): every set is kept";
        }
        if ($settings->retentionMode === 'gfs') {
            $tiers = [];
            foreach (self::PERIODS as $field => [, $word]) {
                if ($count = self::count($settings->$field)) {
                    $tiers[] = "$count " . ($count === 1 ? $word : $word . 's');
                }
            }
            return 'Grandfather-father-son: the newest of each of the last ' . implode(', ', $tiers);
        }
        $days = self::count($settings->deleteBackupsOlderThan);
        $min  = self::count($settings->keepMinBackups);
        return "older than $days " . ($days === 1 ? 'day' : 'days') . ' deleted, keep at least ' . ($min ?: 'none');
    }
}
