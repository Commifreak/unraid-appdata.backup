<?php

namespace unraid\plugins\AppdataBackup;

/**
 * ZFS and btrfs snapshots, so containers can restart before their data is archived
 */
class ABSnapshot {

    const PREFIX = 'appdata.backup_';
    const SETUP_FAILED = 96; // exit code of a command() whose snapshot mounts failed; tar itself exits 0-2

    /**
     * @var array Snapshots taken in this run: type, root (dataset mountpoint or subvolume) and name (ZFS snapshot or btrfs snapshot folder)
     */
    private static array $active = [];

    /**
     * Snapshots the filesystems holding $volumes. Takes nothing and returns false if any volume cannot be snapshotted.
     * @param array $volumes
     * @return bool
     */
    public static function create(array $volumes) {
        $sources = [];
        foreach ($volumes as $volume) {
            $real   = realpath($volume);
            $source = $real === false ? null : self::sourceOf($real);
            if (!$source) {
                $reason = $real === false ? 'was not found' : 'is on ' . self::fsType($real) . ', not ZFS or btrfs';
                ABHelper::backupLog("'$volume' $reason, so no snapshot is possible.");
                return false;
            }
            $nested = self::nestedIn($real, $source);
            if ($nested !== null) {
                ABHelper::backupLog("'$volume' contains '$nested', which a snapshot would leave out, so no snapshot is possible.");
                return false;
            }
            $sources[$source['root']] = $source;
        }

        $tag = self::PREFIX . date('Ymd_His');
        foreach ($sources as $source) {
            self::removeStale($source);
            $snapshot = $source['type'] == 'zfs' ? self::createZfs($source, $tag) : self::createBtrfs($source, $tag);
            if (!$snapshot) {
                self::destroyAll();
                return false;
            }
            self::$active[] = $snapshot;
            ABHelper::backupLog("Snapshot created: " . $snapshot['name']);
        }
        @mkdir(ABSettings::$tempFolder . '/snapshot'); // command() mounts the snapshots below it
        return true;
    }

    /**
     * Removes every snapshot this run created
     * @return void
     */
    public static function destroyAll() {
        foreach (array_reverse(self::$active) as $snapshot) {
            if (self::destroy($snapshot)) {
                ABHelper::backupLog("Snapshot removed: " . $snapshot['name'], ABHelper::LOGLEVEL_DEBUG);
            }
        }
        self::$active = [];
    }

    /**
     * $cmd wrapped to run in a private mount namespace where the active snapshots are mounted over the volumes they cover
     * @param string $cmd
     * @param array $volumes
     * @return string
     */
    public static function command($cmd, array $volumes) {
        $binds = [];
        foreach ($volumes as $volume) {
            $real     = realpath($volume);
            $snapshot = $real === false ? null : self::covering($real);
            if ($snapshot) {
                $binds[$real] = $snapshot;
            }
        }
        if (!$binds) {
            return $cmd;
        }
        ksort($binds, SORT_STRING); // outer volumes are mounted before the volumes inside them

        // ZFS mounts a snapshot only on an empty folder, so each snapshot gets one and the volumes are bound from there
        $dir   = ABSettings::$tempFolder . '/snapshot';
        $steps = ['mount -t tmpfs tmpfs ' . escapeshellarg($dir)];
        $views = [];
        foreach ($binds as $snapshot) {
            if (!isset($views[$snapshot['name']])) {
                $view                     = "$dir/" . count($views);
                $views[$snapshot['name']] = $view;
                $steps[]                  = 'mkdir ' . escapeshellarg($view);
                $steps[]                  = 'mount ' . ($snapshot['type'] == 'zfs' ? '-t zfs -o ro ' : '--bind ') . escapeshellarg($snapshot['name']) . ' ' . escapeshellarg($view);
            }
        }
        foreach ($binds as $real => $snapshot) {
            $steps[] = 'mount --bind ' . escapeshellarg($views[$snapshot['name']] . substr($real, strlen($snapshot['root']))) . ' ' . escapeshellarg($real);
        }
        return 'unshare -m -- sh -c ' . escapeshellarg('{ ' . implode(' && ', $steps) . '; } || exit ' . self::SETUP_FAILED . '; exec ' . $cmd);
    }

    /**
     * Logs and returns true if a command() failed while mounting the snapshots, so tar did not run
     * @param int $resultcode
     * @param array $output
     * @return bool
     */
    public static function setupFailed($resultcode, array $output) {
        if ($resultcode != self::SETUP_FAILED) {
            return false;
        }
        ABHelper::backupLog("Mounting the snapshot failed, so tar did not run! Mount said: " . implode('; ', $output), ABHelper::LOGLEVEL_ERR);
        return true;
    }

    /**
     * ZFS dataset or btrfs subvolume holding the real path $real, or null
     * @param string $real
     * @return array|null
     */
    private static function sourceOf($real) {
        $type = self::fsType($real);
        if ($type == 'zfs') {
            $best = null;
            foreach (self::zfsMounts() as $dataset => $mountpoint) {
                if (($real === $mountpoint || str_starts_with($real, $mountpoint . '/')) && strlen($mountpoint) > strlen($best['root'] ?? '')) {
                    $best = ['type' => 'zfs', 'root' => $mountpoint, 'dataset' => $dataset];
                }
            }
            return $best;
        }

        if ($type == 'btrfs') {
            for ($dir = is_dir($real) ? $real : dirname($real); $dir !== '/'; $dir = dirname($dir)) {
                if (fileinode($dir) === 256) { // btrfs subvolume roots always have inode 256
                    return ['type' => 'btrfs', 'root' => $dir, 'into' => self::btrfsFolder($dir, $real)];
                }
            }
        }
        return null;
    }

    /**
     * Filesystem type of $real, e.g. zfs, btrfs, or fuse for a share under /mnt/user that is not exclusive
     * @param string $real
     * @return string
     */
    private static function fsType($real) {
        return trim((string)shell_exec('stat -f -c %T ' . escapeshellarg($real) . ' 2>/dev/null'));
    }

    /**
     * First mountpoint or nested btrfs subvolume inside $real, or null: a snapshot shows them as empty folders
     * @param string $real
     * @param array $source
     * @return string|null
     */
    private static function nestedIn($real, $source) {
        $paths = [];
        foreach (file('/proc/mounts', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $paths[] = stripcslashes(explode(' ', $line)[1] ?? '');
        }
        if ($source['type'] == 'btrfs') {
            // Both print paths from the top of the filesystem, so the root's own path is cut off the listed ones
            $show = $lines = [];
            exec('btrfs subvolume show ' . escapeshellarg($source['root']) . ' 2>/dev/null', $show);
            exec('btrfs subvolume list -o ' . escapeshellarg($source['root']) . ' 2>/dev/null', $lines);
            $prefix = trim($show[0] ?? '/') === '/' ? '' : trim($show[0]) . '/';
            foreach ($lines as $line) {
                $path    = preg_replace('/^.*? path /', '', $line);
                $paths[] = $source['root'] . '/' . (str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path);
            }
        }
        foreach ($paths as $path) {
            // .zfs mounts are ZFS's own snapshot views, which tar never reads; stale snapshots of this plugin get removed
            if (str_starts_with($path, $real . '/') && !str_contains($path, '/.zfs/') && !str_starts_with(basename($path), '.' . self::PREFIX)) {
                return $path;
            }
        }
        return null;
    }

    /**
     * Mountpoints of all mounted ZFS datasets
     * @return array dataset => mountpoint
     */
    private static function zfsMounts() {
        static $mounts = null;
        if ($mounts === null) {
            $mounts = $lines = [];
            exec('zfs list -H -o name,mountpoint,mounted -t filesystem 2>/dev/null', $lines);
            foreach ($lines as $line) {
                [$name, $mountpoint, $mounted] = array_pad(explode("\t", $line, 3), 3, '');
                if ($mounted === 'yes' && str_starts_with($mountpoint, '/')) {
                    $mounts[$name] = rtrim($mountpoint, '/') ?: '/';
                }
            }
        }
        return $mounts;
    }

    /**
     * Folder for btrfs snapshots: inside the share, because Unraid treats a pool's top-level folders as shares
     * @param string $root
     * @param string $real
     * @return string
     */
    private static function btrfsFolder($root, $real) {
        $share = explode('/', ltrim(substr($real, strlen($root)), '/'))[0];
        return substr_count($root, '/') > 2 || $share === '' ? $root : $root . '/' . $share;
    }

    private static function createZfs($source, $tag) {
        $name = $source['dataset'] . '@' . $tag;
        $out  = $code = null;
        exec('zfs snapshot ' . escapeshellarg($name) . ' 2>&1', $out, $code);
        if ($code != 0) {
            ABHelper::backupLog("Creating snapshot $name failed: " . implode('; ', $out), ABHelper::LOGLEVEL_WARN);
            return null;
        }
        return ['type' => 'zfs', 'root' => $source['root'], 'name' => $name];
    }

    private static function createBtrfs($source, $tag) {
        $path = $source['into'] . '/.' . $tag;
        $out  = $code = null;
        exec('btrfs subvolume snapshot -r ' . escapeshellarg($source['root']) . ' ' . escapeshellarg($path) . ' 2>&1', $out, $code);
        if ($code != 0) {
            ABHelper::backupLog("Creating snapshot $path failed: " . implode('; ', $out), ABHelper::LOGLEVEL_WARN);
            return null;
        }
        return ['type' => 'btrfs', 'root' => $source['root'], 'name' => $path];
    }

    /**
     * Removes snapshots of this source that an interrupted run left behind
     * @param array $source
     * @return void
     */
    private static function removeStale($source) {
        $stale = [];
        if ($source['type'] == 'zfs') {
            exec('zfs list -H -t snapshot -o name -d 1 ' . escapeshellarg($source['dataset']) . ' 2>/dev/null', $stale);
        } else {
            $stale = glob($source['into'] . '/.' . self::PREFIX . '*', GLOB_ONLYDIR) ?: [];
        }
        foreach ($stale as $name) {
            if (in_array($name, array_column(self::$active, 'name'))) {
                continue;
            }
            if (self::destroy(['type' => $source['type'], 'name' => $name])) {
                ABHelper::backupLog("Removed a snapshot left by an earlier run: $name", ABHelper::LOGLEVEL_WARN);
            }
        }
    }

    /**
     * Removes one snapshot, only if it carries this plugin's prefix
     * @param array $snapshot
     * @return bool
     */
    private static function destroy($snapshot) {
        if ($snapshot['type'] == 'zfs') {
            if (!str_contains($snapshot['name'], '@' . self::PREFIX)) {
                return false;
            }
            $cmd = 'zfs destroy ' . escapeshellarg($snapshot['name']);
        } else {
            // A symlink's realpath() differs, and btrfs would delete the subvolume it points to
            if (!str_starts_with(basename($snapshot['name']), '.' . self::PREFIX) || realpath($snapshot['name']) !== $snapshot['name']) {
                return false;
            }
            $cmd = 'btrfs subvolume delete ' . escapeshellarg($snapshot['name']);
        }

        $out = $code = null;
        exec($cmd . ' 2>&1', $out, $code);
        if ($code != 0) {
            ABHelper::backupLog("Removing snapshot {$snapshot['name']} failed: " . implode('; ', $out), ABHelper::LOGLEVEL_WARN);
            return false;
        }
        return true;
    }

    /**
     * Active snapshot whose root is the longest prefix of the real path $real, or null
     * @param string $real
     * @return array|null
     */
    private static function covering($real) {
        $best = null;
        foreach (self::$active as $snapshot) {
            if (($real === $snapshot['root'] || str_starts_with($real, $snapshot['root'] . '/')) && strlen($snapshot['root']) > strlen($best['root'] ?? '')) {
                $best = $snapshot;
            }
        }
        return $best;
    }
}
