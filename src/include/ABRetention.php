<?php

namespace unraid\plugins\AppdataBackup;

require_once __DIR__ . '/ABStatus.php';

/** Which backup sets a run's retention keeps: By age, or grandfather-father-son (GFS). backup.php deletes the rest. */
class ABRetention {

    /** GFS: each field, how a set's time names its period (ISO weeks, Monday first) in this server's time zone, and its word */
    const PERIODS = ['keepDaily' => ['Y-m-d', 'day'], 'keepWeekly' => ['o-W', 'week'], 'keepMonthly' => ['Y-m', 'month'], 'keepYearly' => ['Y', 'year']];

    /** A count or day field as retention reads it: a whole number from 1 to 999999, else 0 (off) */
    public static function count($value): int {
        return preg_match('/^\d{1,6}$/D', trim((string)$value)) ? (int)$value : 0;
    }

    /** Why these settings keep every set, or '' when retention deletes */
    public static function off(ABSettings $settings): string {
        if ($settings->retentionMode === 'gfs') {
            $fields = array_keys(self::PERIODS);
            if (array_filter($fields, fn($field) => self::count($settings->$field))) {
                return '';
            }
            return array_filter($fields, fn($field) => trim((string)$settings->$field) !== '') ? 'none of the four Grandfather-father-son fields is a whole number above 0' : 'the four Grandfather-father-son fields are empty';
        }
        $days = trim((string)$settings->deleteBackupsOlderThan);
        return self::count($days) ? '' : 'Delete backups if older than x days is ' . ($days === '' ? 'empty' : "\"$days\"");
    }

    /**
     * The sets to keep, each with why, while off() is ''. $sets: this run's type, newest first; $good: those that finished without errors.
     * A failed set is never kept as a GFS period's set, nor counted towards Keep at least.
     */
    public static function keep(ABSettings $settings, array $sets, array $good, \DateTime $now): array {
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
