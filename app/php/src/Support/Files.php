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

    public static function copyDir(string $source, string $target, array $skipFiles = []): void
    {
        if (!is_dir($source)) throw new RuntimeException('SOURCE_DIRECTORY_MISSING');
        self::ensureDir($target);
        $skip=[];
        foreach($skipFiles as $relative){
            $relative=str_replace('\\','/',ltrim((string)$relative,'/'));
            if($relative!=='')$skip[$relative]=true;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO | \FilesystemIterator::KEY_AS_PATHNAME),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            $relative = str_replace(DIRECTORY_SEPARATOR,'/',substr($item->getPathname(), strlen(rtrim($source, DIRECTORY_SEPARATOR)) + 1));
            $dest = $target . DIRECTORY_SEPARATOR . str_replace('/',DIRECTORY_SEPARATOR,$relative);
            if ($item->isLink()) throw new RuntimeException('SYMLINK_NOT_ALLOWED:'.$relative);
            if ($item->isDir()) {
                if (file_exists($dest) && !is_dir($dest)) throw new RuntimeException('COPY_TYPE_CONFLICT_EXPECTED_DIRECTORY:'.$relative);
                self::ensureDir($dest);
                continue;
            }
            if (isset($skip[$relative])) continue;
            if (is_dir($dest)) throw new RuntimeException('COPY_TYPE_CONFLICT_EXPECTED_FILE:'.$relative);
            self::ensureDir(dirname($dest));
            if (!is_readable($item->getPathname())) throw new RuntimeException('COPY_SOURCE_NOT_READABLE:'.$relative);
            if (is_file($dest) && !is_writable($dest)) throw new RuntimeException('COPY_TARGET_FILE_NOT_WRITABLE:'.$relative);
            if (!is_file($dest) && !is_writable(dirname($dest))) throw new RuntimeException('COPY_TARGET_DIRECTORY_NOT_WRITABLE:'.$relative);
            if (!@copy($item->getPathname(), $dest)) {
                $last=error_get_last();
                $reason=is_array($last)?preg_replace('/\\s+/',' ',(string)($last['message']??'')):'';
                throw new RuntimeException('COPY_FAILED:'.$relative.($reason!==''?':'.$reason:''));
            }
        }
    }

    public static function beginOverlay(string $source, string $target, string $backupRoot): array
    {
        if (!is_dir($source)) throw new RuntimeException('SOURCE_DIRECTORY_MISSING');
        $parent=dirname($target);
        if (!is_dir($target) && (!is_dir($parent) || !is_writable($parent))) {
            throw new RuntimeException('OVERLAY_TARGET_PARENT_NOT_WRITABLE');
        }
        if (is_dir($target) && !is_writable($target)) throw new RuntimeException('OVERLAY_TARGET_NOT_WRITABLE');

        if (file_exists($backupRoot)) self::removeTree($backupRoot);
        self::ensureDir($backupRoot,0700);

        $existingFiles=[];
        $unchangedFiles=[];
        $newFiles=[];
        $newDirs=[];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO | \FilesystemIterator::KEY_AS_PATHNAME),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            $relative=str_replace(DIRECTORY_SEPARATOR,'/',substr($item->getPathname(), strlen(rtrim($source, DIRECTORY_SEPARATOR)) + 1));
            $dest=$target . DIRECTORY_SEPARATOR . str_replace('/',DIRECTORY_SEPARATOR,$relative);
            if ($item->isLink()) throw new RuntimeException('SYMLINK_NOT_ALLOWED:'.$relative);

            if ($item->isDir()) {
                if (file_exists($dest) && !is_dir($dest)) throw new RuntimeException('OVERLAY_TYPE_CONFLICT_EXPECTED_DIRECTORY:'.$relative);
                if (!file_exists($dest)) $newDirs[]=$relative;
                continue;
            }

            if (is_dir($dest)) throw new RuntimeException('OVERLAY_TYPE_CONFLICT_EXPECTED_FILE:'.$relative);
            if (!is_readable($item->getPathname())) throw new RuntimeException('OVERLAY_SOURCE_NOT_READABLE:'.$relative);

            if (is_file($dest)) {
                if (!is_readable($dest)) throw new RuntimeException('OVERLAY_EXISTING_FILE_NOT_READABLE:'.$relative);

                $sourceHash=@hash_file('sha256',$item->getPathname());
                $destHash=@hash_file('sha256',$dest);
                if(is_string($sourceHash)&&is_string($destHash)&&$sourceHash!==''&&$destHash!==''&&hash_equals($sourceHash,$destHash)){
                    // A retry may encounter an immutable release file that is
                    // already present and currently executing. Identical bytes
                    // are already satisfied and must never be recopied.
                    $unchangedFiles[]=$relative;
                    continue;
                }

                $normalized=str_replace('\\','/',$relative);
                if(str_starts_with($normalized,'go-engine/bin/releases/')){
                    // Immutable release paths may never be changed in place.
                    // A different byte stream at the same release path means
                    // the package/release identity is inconsistent.
                    throw new RuntimeException('IMMUTABLE_RELEASE_COLLISION:'.$relative);
                }

                if (!is_writable($dest)) throw new RuntimeException('OVERLAY_EXISTING_FILE_NOT_WRITABLE:'.$relative);
                $backup=$backupRoot . DIRECTORY_SEPARATOR . str_replace('/',DIRECTORY_SEPARATOR,$relative);
                self::ensureDir(dirname($backup),0700);
                if (!@copy($dest,$backup)) throw new RuntimeException('OVERLAY_BACKUP_FAILED:'.$relative);
                $existingFiles[]=$relative;
            } else {
                $probe=dirname($dest);
                while(!is_dir($probe) && $probe!==dirname($probe)) $probe=dirname($probe);
                if (!is_dir($probe) || !is_writable($probe)) throw new RuntimeException('OVERLAY_TARGET_DIRECTORY_NOT_WRITABLE:'.$relative);
                $newFiles[]=$relative;
            }
        }

        return [
            'source'=>$source,
            'target'=>$target,
            'backupRoot'=>$backupRoot,
            'existingFiles'=>$existingFiles,
            'unchangedFiles'=>$unchangedFiles,
            'newFiles'=>$newFiles,
            'newDirs'=>$newDirs,
        ];
    }

    public static function applyOverlay(array $plan): void
    {
        try {
            self::copyDir((string)$plan['source'],(string)$plan['target'],(array)($plan['unchangedFiles']??[]));
        } catch (\Throwable $e) {
            try { self::rollbackOverlay($plan); }
            catch (\Throwable $restore) {
                throw new RuntimeException('OVERLAY_APPLY_FAILED_RESTORE_FAILED:'.$e->getMessage().':'.$restore->getMessage(),0,$e);
            }
            throw $e;
        }
    }

    public static function rollbackOverlay(array $plan): void
    {
        $target=(string)($plan['target']??'');
        $backupRoot=(string)($plan['backupRoot']??'');

        foreach (array_reverse((array)($plan['newFiles']??[])) as $relative) {
            $dest=$target . DIRECTORY_SEPARATOR . str_replace('/',DIRECTORY_SEPARATOR,(string)$relative);
            if (is_file($dest) || is_link($dest)) @unlink($dest);
        }

        foreach ((array)($plan['existingFiles']??[]) as $relative) {
            $backup=$backupRoot . DIRECTORY_SEPARATOR . str_replace('/',DIRECTORY_SEPARATOR,(string)$relative);
            $dest=$target . DIRECTORY_SEPARATOR . str_replace('/',DIRECTORY_SEPARATOR,(string)$relative);
            if (!is_file($backup)) throw new RuntimeException('OVERLAY_RESTORE_BACKUP_MISSING:'.$relative);
            self::ensureDir(dirname($dest));
            if (!@copy($backup,$dest)) throw new RuntimeException('OVERLAY_RESTORE_FAILED:'.$relative);
        }

        foreach (array_reverse((array)($plan['newDirs']??[])) as $relative) {
            $dest=$target . DIRECTORY_SEPARATOR . str_replace('/',DIRECTORY_SEPARATOR,(string)$relative);
            if (is_dir($dest)) @rmdir($dest);
        }

        if ($backupRoot!=='' && file_exists($backupRoot)) self::removeTree($backupRoot);
    }

    public static function commitOverlay(array $plan): void
    {
        $backupRoot=(string)($plan['backupRoot']??'');
        if ($backupRoot!=='' && file_exists($backupRoot)) {
            try { self::removeTree($backupRoot); } catch (\Throwable) {}
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
