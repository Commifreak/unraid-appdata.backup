<?php

namespace unraid\plugins\AppdataBackup;

require_once __DIR__ . '/ABHelper.php';

/** Backup set summary for the Status / Log page */
class ABStatus {

    const SET_PATTERN = '/^ab_(\d{8}_\d{6})(-failed)?$/';

    /** Days without a successful backup before the page warns, by schedule */
    const STALE_DAYS = ['daily' => 2, 'weekly' => 9, 'monthly' => 35];

    /** Sets in $destination, newest first: path, date, state (ok, failed, incomplete) and size in bytes */
    public static function sets($destination) {
        $sets = [];
        foreach (glob(rtrim($destination, '/') . '/ab_*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (!preg_match(self::SET_PATTERN, basename($dir), $m)) {
                continue;
            }
            $size = 0;
            foreach (glob($dir . '/*') ?: [] as $file) {
                $size += is_file($file) ? (int)filesize($file) : 0;
            }
            $sets[] = [
                'path'  => $dir,
                'date'  => \DateTime::createFromFormat('Ymd_His', $m[1]),
                'state' => !empty($m[2]) ? 'failed' : (file_exists($dir . '/backup.log') ? 'ok' : 'incomplete'),
                'size'  => $size,
            ];
        }
        usort($sets, fn($a, $b) => $b['date'] <=> $a['date']);
        return $sets;
    }

    /** Seconds from the first to the last line of a set's backup.log, or null */
    public static function duration($set) {
        $log   = $set['path'] . '/backup.log';
        $lines = is_file($log) ? file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;
        if (!$lines) {
            return null;
        }
        $first = self::logTime(reset($lines));
        $last  = self::logTime(end($lines));
        return $first && $last ? $last->getTimestamp() - $first->getTimestamp() : null;
    }

    private static function logTime($line) {
        return preg_match('/^\[(\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}:\d{2})\]/', $line, $m) ? \DateTime::createFromFormat('d.m.Y H:i:s', $m[1]) : null;
    }

    /** Next scheduled run, or null for a custom or disabled schedule */
    public static function nextRun(ABSettings $settings, \DateTime $now) {
        $at = fn(\DateTime $day) => (clone $day)->setTime((int)$settings->backupFrequencyHour, (int)$settings->backupFrequencyMinute);
        switch ($settings->backupFrequency) {
            case 'daily':
                $next = $at($now);
                return $next > $now ? $next : $next->modify('+1 day');
            case 'weekly':
                $next = $at($now)->modify('+' . (((int)$settings->backupFrequencyWeekday - (int)$now->format('w') + 7) % 7) . ' days');
                return $next > $now ? $next : $next->modify('+7 days');
            case 'monthly':
                $day = (int)$settings->backupFrequencyDayOfMonth;
                for ($month = 0; $month < 13; $month++) {
                    $first = (clone $now)->modify('first day of this month')->modify("+$month month");
                    if ($day <= (int)$first->format('t')) {
                        $next = $at($first->setDate((int)$first->format('Y'), (int)$first->format('n'), $day));
                        if ($next > $now) {
                            return $next;
                        }
                    }
                }
        }
        return null;
    }

    /** Everything the Status / Log page shows; computed once per page load */
    public static function summary(ABSettings $settings) {
        $now     = new \DateTime();
        $sets    = empty($settings->destination) ? [] : self::sets($settings->destination);
        $ok      = array_values(array_filter($sets, fn($set) => $set['state'] === 'ok'));
        $failed  = array_values(array_filter($sets, fn($set) => $set['state'] === 'failed'));
        $free    = empty($settings->destination) ? false : @disk_free_space($settings->destination);
        $stale   = self::STALE_DAYS[$settings->backupFrequency] ?? null;
        $ageDays = $ok ? (int)floor(($now->getTimestamp() - $ok[0]['date']->getTimestamp()) / 86400) : null;

        return [
            'latest'     => $sets[0] ?? null,
            'running'    => (bool)ABHelper::scriptRunning(),
            'ok'         => $ok,
            'failed'     => $failed,
            'okSize'     => array_sum(array_column($ok, 'size')),
            'failedSize' => array_sum(array_column($failed, 'size')),
            'next'       => self::nextRun($settings, $now),
            'free'       => $free,
            'stale'      => $stale !== null && ($ageDays === null || $ageDays >= $stale) ? $ageDays : false,
            'lowSpace'   => $free !== false && $ok && $free < $ok[0]['size'],
        ];
    }

    public static function bytes($bytes) {
        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $unit) {
            if ($bytes < 1024 || $unit === 'TB') {
                return round($bytes, $unit === 'B' ? 0 : 1) . ' ' . $unit;
            }
            $bytes /= 1024;
        }
    }

    public static function minutes($seconds) {
        $minutes = (int)round($seconds / 60);
        return $minutes < 60 ? "$minutes min" : intdiv($minutes, 60) . ' h ' . ($minutes % 60) . ' min';
    }
}
