<?php

namespace unraid\plugins\AppdataBackup;

require_once __DIR__ . '/ABHelper.php';

/** Backup set summary for the Status / Log page */
class ABStatus {

    const SET_PATTERN = '/^ab_(\d{8}_\d{6})(-failed)?$/';

    /** Written to the flash at the end of each run, so opening the page never reads the backup disks */
    const CACHE = 'status.json';
    const CACHE_EXTRA = 'status-extra.json';

    /** Days without a successful backup before the page warns, by schedule */
    const STALE_DAYS = ['daily' => 2, 'weekly' => 9, 'monthly' => 35];

    /** The time in a set folder's name (ab_YYYYMMDD_HHMMSS[-failed]), or null when it is not a set */
    public static function setTime($name) {
        return preg_match(self::SET_PATTERN, $name, $m) ? \DateTime::createFromFormat('Ymd_His', $m[1])->getTimestamp() : null;
    }

    /**
     * For each archive of $set, the age in seconds of a newer successful set holding the same container in the other schedule's destination
     * @return array archive name => age, only for archives that have one
     */
    public static function newerElsewhere(ABSettings $settings, $set, array $archives) {
        if (($setTime = self::setTime(basename($set))) === null) {
            return [];
        }
        $here    = realpath(dirname($set));
        $newest  = [];
        foreach ([$settings->destination, $settings->extraSchedule === 'yes' ? $settings->extraDestination : ''] as $destination) {
            $real = $destination === '' ? false : realpath($destination); // realpath('') is the working directory
            if ($real === false || $real === $here) {
                continue;
            }
            foreach (scandir($real) ?: [] as $name) {
                $time = self::setTime($name);
                if ($time === null || str_ends_with($name, '-failed') || !is_file("$real/$name/backup.log") || $time <= $setTime) {
                    continue;
                }
                // The other schedule may compress differently, so containers are matched by name
                $held = array_map(fn($file) => preg_replace(ABHelper::ARCHIVE_PATTERN, '', $file), preg_grep(ABHelper::ARCHIVE_PATTERN, scandir("$real/$name") ?: []));
                foreach ($archives as $archive) {
                    if (in_array(preg_replace(ABHelper::ARCHIVE_PATTERN, '', $archive), $held, true)) {
                        $newest[$archive] = max($newest[$archive] ?? 0, $time);
                    }
                }
            }
        }
        return array_map(fn($time) => time() - $time, $newest);
    }

    /** Sets in $destination, newest first: path, date, state (ok, failed, incomplete) and size in bytes; false if a listing fails */
    public static function sets($destination) {
        $destination = rtrim($destination, '/');
        // scandir, not glob: a [ or ? in the destination would be read as a pattern
        if (($names = scandir($destination)) === false) {
            return false;
        }
        $sets = [];
        foreach ($names as $name) {
            $dir = $destination . '/' . $name;
            if (!preg_match(self::SET_PATTERN, $name, $m) || !is_dir($dir)) {
                continue;
            }
            if (($size = self::size($dir)) === false) {
                return false;
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

    /** Bytes in the files of $dir, or false if it cannot be listed or a size cannot be read */
    private static function size($dir) {
        if (($files = scandir($dir)) === false) {
            return false;
        }
        $size = 0;
        foreach ($files as $file) {
            $bytes = is_file($dir . '/' . $file) ? filesize($dir . '/' . $file) : 0;
            if ($bytes === false) {
                return false;
            }
            $size += $bytes;
        }
        return $size;
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

    /** Each schedule keeps its own summary, so an extra run leaves the main one alone */
    private static function cachePath(ABSettings $settings) {
        return ABSettings::$pluginDir . '/' . ($settings->schedule === 'extra' ? self::CACHE_EXTRA : self::CACHE);
    }

    /** Records the sets and the run's duration for summary(); runs at the end of a backup, while the backup disks are awake anyway */
    public static function saveSummary(ABSettings $settings, $duration) {
        // A failed listing keeps the last summary rather than recording "no sets"
        if (empty($settings->destination) || ($sets = self::sets($settings->destination)) === false) {
            return;
        }
        file_put_contents(self::cachePath($settings), json_encode([
            'recorded'    => time(),
            'destination' => $settings->destination,
            'duration'    => $duration,
            // Set names, not timestamps: a writer in another timezone (php -r skips local_prepend.php) cannot shift them
            'sets'        => array_map(fn($set) => ['date' => $set['date']->format('Ymd_His'), 'state' => $set['state'], 'size' => $set['size']], $sets),
        ]));
    }

    /** Everything the Status / Log page shows: the sets as recorded by the last run, free space and the next run live */
    public static function summary(ABSettings $settings) {
        $now     = new \DateTime();
        $cache   = json_decode((string)@file_get_contents(self::cachePath($settings)), true);
        $cache   = is_array($cache) && isset($cache['recorded'], $cache['sets']) && ($cache['destination'] ?? null) === $settings->destination ? $cache : null;
        $sets    = array_map(fn($set) => ['date' => \DateTime::createFromFormat('Ymd_His', (string)$set['date'])] + $set, $cache['sets'] ?? []);
        $ok      = array_values(array_filter($sets, fn($set) => $set['state'] === 'ok'));
        $failed  = array_values(array_filter($sets, fn($set) => $set['state'] === 'failed'));
        $free    = empty($settings->destination) ? false : @disk_free_space($settings->destination);
        $stale   = $cache ? (self::STALE_DAYS[$settings->backupFrequency] ?? null) : null;
        $ageDays = $ok ? (int)floor(($now->getTimestamp() - $ok[0]['date']->getTimestamp()) / 86400) : null;

        return [
            'recorded'   => $cache ? (new \DateTime())->setTimestamp((int)$cache['recorded']) : null,
            'latest'     => $sets[0] ?? null,
            'duration'   => $cache['duration'] ?? null,
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

    public static function minutes($seconds) {
        $minutes = (int)round($seconds / 60);
        return $minutes < 60 ? "$minutes min" : intdiv($minutes, 60) . ' h ' . ($minutes % 60) . ' min';
    }
}
