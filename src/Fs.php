<?php

declare(strict_types=1);

namespace Pholio;

require_once __DIR__ . '/Exceptions.php';

/**
 * File system helpers for the build: writing, copying, temporary directories
 * and the two-way comparison behind `pholio check`. Failures are IoException.
 */
final class Fs
{
    /** Write $content plus a final newline to $root/$relative, creating directories. */
    public static function write(string $root, string $relative, string $content): void
    {
        $file = rtrim($root, '/') . '/' . ltrim($relative, '/');
        self::mkdir(dirname($file));
        if (@file_put_contents($file, $content . "\n") === false) {
            throw new IoException('file not writable: ' . $file);
        }
    }

    public static function mkdir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0o777, true) && !is_dir($dir)) {
            throw new IoException('directory cannot be created: ' . $dir);
        }
    }

    /** @return list<string> file names (no directories, no hidden files) directly in $dir */
    public static function files(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name[0] !== '.' && is_file($dir . '/' . $name)) {
                $out[] = $name;
            }
        }

        return $out;
    }

    public static function copyFile(string $from, string $to): void
    {
        self::mkdir(dirname($to));
        if (!@copy($from, $to)) {
            throw new IoException('file cannot be copied: ' . $from . ' to ' . $to);
        }
    }

    /**
     * Copy a directory recursively: files only, symbolic links are followed.
     *
     * @param null|callable(string):bool $filter receives the path relative to $from;
     *        false skips the file
     */
    public static function copyDir(string $from, string $to, ?callable $filter = null): void
    {
        foreach (self::treeFiles($from) as $relative) {
            if ($filter === null || $filter($relative)) {
                self::copyFile($from . '/' . $relative, $to . '/' . $relative);
            }
        }
    }

    public static function tempDir(string $prefix = 'pholio-build-'): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6));
        self::mkdir($dir);

        return $dir;
    }

    /** @return list<string> every file below $dir, relative to $dir, sorted */
    public static function treeFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $dir = rtrim($dir, '/');
        $out = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $out[] = substr($file->getPathname(), strlen($dir) + 1);
            }
        }
        sort($out, SORT_STRING);

        return $out;
    }

    /**
     * Compare a fresh build with an existing output directory, both ways.
     *
     * Lines are `missing: <path>` (built but absent from $existing),
     * `stale: <path>` (contents differ) and `extra: <path>` (in $existing, not
     * built and not kept).
     *
     * @param list<string> $keep paths under $existing that the build never writes
     * @return list<string>
     */
    public static function compare(string $fresh, string $existing, array $keep = []): array
    {
        $fresh = rtrim($fresh, '/');
        $existing = rtrim($existing, '/');
        $out = [];
        foreach (self::treeFiles($fresh) as $relative) {
            $other = $existing . '/' . $relative;
            if (!is_file($other)) {
                $out[] = 'missing: ' . $relative;
                continue;
            }
            if (!self::sameContents($fresh . '/' . $relative, $other)) {
                $out[] = 'stale: ' . $relative;
            }
        }
        foreach (self::treeFiles($existing) as $relative) {
            if (is_file($fresh . '/' . $relative) || self::isKept($relative, $keep)) {
                continue;
            }
            $out[] = 'extra: ' . $relative;
        }

        return $out;
    }

    /** @param list<string> $keep */
    public static function isKept(string $relative, array $keep): bool
    {
        foreach ($keep as $path) {
            $path = trim($path, '/');
            if ($path !== '' && ($relative === $path || str_starts_with($relative, $path . '/'))) {
                return true;
            }
        }

        return false;
    }

    public static function removeDir(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $ok = $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            if (!$ok) {
                throw new IoException('cannot remove: ' . $item->getPathname());
            }
        }
        if (!@rmdir($dir)) {
            throw new IoException('cannot remove: ' . $dir);
        }
    }

    private static function sameContents(string $a, string $b): bool
    {
        return filesize($a) === filesize($b) && hash_file('xxh128', $a) === hash_file('xxh128', $b);
    }
}
