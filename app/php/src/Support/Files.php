<?php
declare(strict_types=1);

namespace DigiOps\Support;

use RuntimeException;

final class Files
{
    public static function ensureDir(string $dir, int $mode = 0750): void
    {
        if (is_dir($dir)) return;
        if (!mkdir($dir, $mode, true) && !is_dir($dir)) {
            throw new RuntimeException('DIRECTORY_CREATE_FAILED');
        }
    }

    public static function readJson(string $file, array $default = []): array
    {
        if (!is_file($file)) return $default;
        $raw = file_get_contents($file);
        $data = json_decode($raw ?: '', true);
        return is_array($data) ? $data : $default;
    }

    public static function readJsonStrict(string $file, array $default = []): array
    {
        if (!is_file($file)) return $default;
        $raw=file_get_contents($file);
        if($raw===false) throw new RuntimeException('JSON_STATE_READ_FAILED');
        $data=json_decode($raw,true);
        if(!is_array($data)) throw new RuntimeException('JSON_STATE_INVALID');
        return $data;
    }

    public static function withLock(string $lockFile, callable $callback): mixed
    {
        self::ensureDir(dirname($lockFile));
        $fp = fopen($lockFile, 'c+');
        if (!$fp) throw new RuntimeException('LOCK_OPEN_FAILED');
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            throw new RuntimeException('LOCK_ACQUIRE_FAILED');
        }
        try {
            return $callback();
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    public static function mutateJson(string $file, array $default, callable $mutator): array
    {
        return self::withLock($file . '.lock', static function() use ($file, $default, $mutator): array {
            $current = self::readJson($file, $default);
            $next = $mutator($current);
            if (!is_array($next)) throw new RuntimeException('JSON_MUTATOR_INVALID');
            self::writeJson($file, $next);
            return $next;
        });
    }

    public static function mutateJsonStrict(string $file, array $default, callable $mutator): array
    {
        return self::withLock($file . '.lock', static function() use ($file, $default, $mutator): array {
            $current = self::readJsonStrict($file, $default);
            $next = $mutator($current);
            if (!is_array($next)) throw new RuntimeException('JSON_MUTATOR_INVALID');
            self::writeJson($file, $next);
            return $next;
        });
    }

    public static function writeJson(string $file, array $data): void
    {
        self::ensureDir(dirname($file));
        $tmp = $file . '.tmp.' . bin2hex(random_bytes(6));
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false) {
            @unlink($tmp);
            throw new RuntimeException('JSON_WRITE_FAILED');
        }
        @chmod($tmp, 0640);
        if (!rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('ATOMIC_WRITE_FAILED');
        }
    }

    public static function copyDir(string $source, string $target): void
    {
        if (!is_dir($source)) throw new RuntimeException('SOURCE_DIRECTORY_MISSING');
        self::ensureDir($target);
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            $relative = substr($item->getPathname(), strlen(rtrim($source, DIRECTORY_SEPARATOR)) + 1);
            $dest = $target . DIRECTORY_SEPARATOR . $relative;
            if ($item->isLink()) throw new RuntimeException('SYMLINK_NOT_ALLOWED');
            if ($item->isDir()) self::ensureDir($dest);
            elseif (!copy($item->getPathname(), $dest)) throw new RuntimeException('COPY_FAILED');
        }
    }

    public static function removeTree(string $path): void
    {
        if (!file_exists($path)) return;
        if (is_link($path) || is_file($path)) {
            if (!@unlink($path)) throw new RuntimeException('REMOVE_FAILED');
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            if ($item->isLink() || $item->isFile()) @unlink($item->getPathname());
            else @rmdir($item->getPathname());
        }
        if (!@rmdir($path)) throw new RuntimeException('REMOVE_FAILED');
    }

    public static function directorySize(string $dir): int
    {
        if (!is_dir($dir)) return 0;
        $size = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $item) if ($item->isFile() && !$item->isLink()) $size += $item->getSize();
        return $size;
    }
}
