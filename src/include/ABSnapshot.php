<?php

namespace unraid\plugins\AppdataBackup;

/**
 * ZFS and btrfs snapshots, so containers can restart before their data is archived
 */
class ABSnapshot {

    const PREFIX = 'appdata.backup_';

    /**
     * @var array Snapshots taken in this run: type, root (dataset mountpoint or subvolume), name, base (readable snapshot root)
     */
    private static array $active = [];

    /**
     * Snapshots the filesystems holding $volumes. Takes nothing and returns false if any volume is not on ZFS or btrfs.
     * @param array $volumes
     * @return bool
     */
    public static function create(array $volumes) {
        $sources = [];
        foreach ($volumes as $volume) {
            $source = self::sourceOf($volume);
            if (!$source) {
                ABHelper::backupLog("'$volume' is not on ZFS or btrfs, so no snapshot is possible.");
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
     * Where $path is inside an active snapshot, or null if no snapshot covers it
     * @param string $path
     * @return string|null
     */
    public static function mapPath($path) {
        $real     = realpath($path);
        $snapshot = $real === false ? null : self::covering($real);
        return $snapshot ? $snapshot['base'] . substr($real, strlen($snapshot['root'])) : null;
    }

    /**
     * Rewrites an absolute exclude pattern onto the snapshot of one of $volumes, so it keeps matching what tar reads.
     * Only the container's own volumes are used, so a share spread over several pools maps to the right one.
     * @param string $pattern
     * @param array $volumes
     * @return string
     */
    public static function mapPattern($pattern, array $volumes) {
        if (!str_starts_with($pattern, '/')) {
            return $pattern;
        }
        $best    = $pattern;
        $bestLen = -1;
        foreach ($volumes as $volume) {
            $real     = realpath($volume);
            $snapshot = $real === false ? null : self::covering($real);
            if (!$snapshot) {
                continue;
            }
            // The snapshot root as this volume sees it, e.g. /mnt/user for /mnt/user/appdata/app on the pool /mnt/cache
            $rel   = substr($real, strlen($snapshot['root']));
            $heads = [$snapshot['root']];
            if (str_ends_with($volume, $rel) && strlen($volume) > strlen($rel)) {
                $heads[] = substr($volume, 0, strlen($volume) - strlen($rel));
            }
            foreach ($heads as $head) {
                if (($pattern === $head || str_starts_with($pattern, $head . '/')) && strlen($head) > $bestLen) {
                    $best    = $snapshot['base'] . substr($pattern, strlen($head));
                    $bestLen = strlen($head);
                }
            }
        }
        return $best;
    }

    /**
     * tar option that renames the leading $from of member names to $to
     * @param string $from
     * @param string $to
     * @return string
     */
    public static function transform($from, $to) {
        $pattern     = preg_replace('/[\\\\.\[\]*^$,]/', '\\\\$0', $from);
        $replacement = preg_replace('/[\\\\&,]/', '\\\\$0', $to);
        return '--transform ' . escapeshellarg('s,^' . $pattern . ',' . $replacement . ',');
    }

    /**
     * ZFS dataset or btrfs subvolume holding $path, or null
     * @param string $path
     * @return array|null
     */
    private static function sourceOf($path) {
        $real = realpath($path);
        if ($real === false) {
            return null;
        }

        $type = trim((string)shell_exec('stat -f -c %T ' . escapeshellarg($real) . ' 2>/dev/null'));
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
     * Mountpoints of all mounted ZFS datasets
     * @return array dataset => mountpoint
     */
    private static function zfsMounts() {
        static $mounts = null;
        if ($mounts === null) {
            $mounts = $lines = [];
            exec('zfs list -H -o name,mountpoint -t filesystem 2>/dev/null', $lines);
            foreach ($lines as $line) {
                [$name, $mountpoint] = array_pad(explode("\t", $line, 2), 2, '');
                if (str_starts_with($mountpoint, '/')) {
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
        return ['type' => 'zfs', 'root' => $source['root'], 'name' => $name, 'base' => $source['root'] . '/.zfs/snapshot/' . $tag];
    }

    private static function createBtrfs($source, $tag) {
        $path = $source['into'] . '/.' . $tag;
        $out  = $code = null;
        exec('btrfs subvolume snapshot -r ' . escapeshellarg($source['root']) . ' ' . escapeshellarg($path) . ' 2>&1', $out, $code);
        if ($code != 0) {
            ABHelper::backupLog("Creating snapshot $path failed: " . implode('; ', $out), ABHelper::LOGLEVEL_WARN);
            return null;
        }
        return ['type' => 'btrfs', 'root' => $source['root'], 'name' => $path, 'base' => $path];
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
            if (!str_starts_with(basename($snapshot['name']), '.' . self::PREFIX)) {
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
