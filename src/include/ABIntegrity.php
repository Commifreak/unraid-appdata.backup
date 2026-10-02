<?php

namespace unraid\plugins\AppdataBackup;

/**
 * Checksums of a backup set, so it can be checked again later without its source data
 */
class ABIntegrity {

    const FILE = 'checksums.sha256';

    /**
     * @var array Archives of containers with verification off: no checksum, which spares them the extra read
     */
    public static array $unverified = [];

    /**
     * Writes FILE into $set, in sha256sum -c format, for $files except the unverified archives
     * @param string $set
     * @param array $files
     * @return void
     */
    public static function writeChecksums($set, array $files) {
        $timer = time();
        $lines = [];
        foreach ($files as $file) {
            $name = basename($file);
            // sha256sum escapes names with a backslash or newline, so those are left out to keep the file valid
            if (!is_file($file) || $name === self::FILE || in_array($name, self::$unverified) || strpbrk($name, "\\\n") !== false) {
                continue;
            }
            $hash = hash_file('sha256', $file);
            if ($hash === false) {
                ABHelper::backupLog("Cannot read $name to write its checksum!", ABHelper::LOGLEVEL_WARN);
                continue;
            }
            $lines[] = "$hash  $name";
        }
        if (file_put_contents($set . '/' . self::FILE, implode("\n", $lines) . "\n") === false) {
            ABHelper::backupLog("Writing " . self::FILE . " failed!", ABHelper::LOGLEVEL_WARN);
            return;
        }
        ABHelper::backupLog("Checksums written for " . count($lines) . " files (took " . gmdate("H:i:s", time() - $timer) . ")");
    }

    /**
     * Checks the files of $set against its FILE; false on a mismatch, a missing file or no FILE
     * @param string $set
     * @return bool
     */
    public static function verifySet($set) {
        $real = realpath($set);
        if ($real === false || !is_dir($real) || !preg_match('/^ab_\d{8}_\d{6}$/', basename($real))) {
            ABHelper::backupLog("'$set' is not a backup set!", ABHelper::LOGLEVEL_ERR);
            return false;
        }
        $list = @file($real . '/' . self::FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$list) {
            ABHelper::backupLog("$real has no " . self::FILE . ", so it cannot be checked. Backups get one from this plugin version on.", ABHelper::LOGLEVEL_ERR);
            return false;
        }

        ABHelper::backupLog("Checking " . count($list) . " files in $real...");
        $timer  = time();
        $failed = 0;
        $listed = [];
        foreach ($list as $line) {
            if (ABHelper::abortRequested()) {
                ABHelper::backupLog("Check cancelled!", ABHelper::LOGLEVEL_WARN);
                return false;
            }
            // Plain names only, so an edited list cannot point outside the set
            if (!preg_match('/^([0-9a-f]{64}) [ *]([^\/]+)$/', $line, $match)) {
                ABHelper::backupLog("Unreadable line in " . self::FILE . ": $line", ABHelper::LOGLEVEL_WARN);
                $failed++;
                continue;
            }
            $listed[] = $match[2];
            if (!is_file($real . '/' . $match[2])) {
                ABHelper::backupLog("{$match[2]} is missing!", ABHelper::LOGLEVEL_WARN);
                $failed++;
            } elseif (hash_file('sha256', $real . '/' . $match[2]) !== $match[1]) {
                ABHelper::backupLog("{$match[2]} does not match its checksum!", ABHelper::LOGLEVEL_WARN);
                $failed++;
            } else {
                ABHelper::backupLog("{$match[2]} OK", ABHelper::LOGLEVEL_DEBUG);
            }
        }

        $unlisted = array_diff(array_map('basename', array_filter(glob($real . '/*') ?: [], 'is_file')), $listed, [self::FILE, 'backup.log', 'backup.debug.log', ABSettings::$settingsFile]);
        if ($unlisted) {
            ABHelper::backupLog("No checksum for: " . implode(', ', $unlisted) . " (verification is off for their container)");
        }
        $took = gmdate("H:i:s", time() - $timer);
        if ($failed) {
            ABHelper::backupLog("$failed of " . count($list) . " files failed the check (took $took)!", ABHelper::LOGLEVEL_ERR);
            return false;
        }
        ABHelper::backupLog("All " . count($list) . " files match their checksums (took $took).");
        return true;
    }
}
