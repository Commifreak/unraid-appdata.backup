<?php

namespace unraid\plugins\AppdataBackup;

/**
 * Checksums of a backup set, so it can be checked again later without its source data
 */
class ABIntegrity {

    const FILE = 'checksums.sha256';

    /**
     * @var array Archives that get no checksum: their container has verification off, or tar failed and the error was ignored
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
            if (ABHelper::abortRequested()) {
                return;
            }
            $name = basename($file);
            // sha256sum escapes names with a backslash or newline, so those are left out to keep the file valid
            if (!is_file($file) || $name === self::FILE || in_array($name, self::$unverified) || strpbrk($name, "\\\n") !== false) {
                continue;
            }
            $hash = hash_file('sha256', $file);
            if ($hash === false) {
                ABHelper::backupLog("Cannot read $name to write its checksum!", ABHelper::LOGLEVEL_ERR);
                ABHelper::$errorOccured = true;
                continue;
            }
            $lines[] = "$hash  $name";
        }
        $content = implode("\n", $lines) . "\n";
        if (file_put_contents($set . '/' . self::FILE, $content) !== strlen($content)) {
            ABHelper::backupLog("Writing " . self::FILE . " failed!", ABHelper::LOGLEVEL_ERR);
            ABHelper::$errorOccured = true;
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
        $manifest = $real . '/' . self::FILE;
        // file() reads it whole: a symlinked or oversized list could point outside the set or exhaust memory
        if (is_link($manifest) || (is_file($manifest) && filesize($manifest) > 1048576)) {
            ABHelper::backupLog(self::FILE . " in $real is not a plain checksum list, so it cannot be checked!", ABHelper::LOGLEVEL_ERR);
            return false;
        }
        $list = @file($manifest, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$list) {
            $why = $list !== false ? 'lists no files' : (file_exists($manifest) ? 'cannot be read' : null);
            ABHelper::backupLog($why ? self::FILE . " in $real $why, so it cannot be checked." : "$real has no " . self::FILE . ", so it cannot be checked. Backups get one from this plugin version on.", ABHelper::LOGLEVEL_ERR);
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
            // Plain names and no symlinks, so an edited list cannot point outside the set
            if (!preg_match('/^([0-9a-f]{64}) [ *]([^\/]+)$/', $line, $match)) {
                ABHelper::backupLog("Unreadable line in " . self::FILE . ": $line", ABHelper::LOGLEVEL_WARN);
                $failed++;
                continue;
            }
            $listed[] = $match[2];
            if (is_link($real . '/' . $match[2]) || !is_file($real . '/' . $match[2])) {
                ABHelper::backupLog("{$match[2]} is missing or not a plain file!", ABHelper::LOGLEVEL_WARN);
                $failed++;
            } elseif (hash_file('sha256', $real . '/' . $match[2]) !== $match[1]) {
                ABHelper::backupLog("{$match[2]} does not match its checksum!", ABHelper::LOGLEVEL_WARN);
                $failed++;
            } else {
                ABHelper::backupLog("{$match[2]} OK", ABHelper::LOGLEVEL_DEBUG);
            }
        }

        $unlisted = array_diff(array_map('basename', array_filter(glob(ABHelper::globQuote($real) . '/*') ?: [], 'is_file')), $listed, [self::FILE, 'backup.log', 'backup.debug.log', ABSettings::$settingsFile]);
        if ($unlisted) {
            ABHelper::backupLog("No checksum for: " . implode(', ', $unlisted) . " (verification off for their container, an ignored tar failure, or a changed list)");
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
