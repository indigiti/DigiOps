<?php
declare(strict_types=1);

namespace DigiOps\Deploy;

use DigiOps\Support\Files;
use RuntimeException;

final class AtomicReleaseSwitcher
{
    /**
     * Replace a live directory with a fully prepared directory on the same filesystem.
     * The previous live tree is retained until the new tree is in place and is restored
     * automatically if the final rename fails.
     */
    public function switch(string $prepared, string $target, string $label): void
    {
        if (!is_dir($prepared)) throw new RuntimeException('PREPARED_RELEASE_MISSING');

        $parent = dirname($target);
        Files::ensureDir($parent);
        $previous = $parent . '/.' . $label . '.previous-' . bin2hex(random_bytes(6));
        $hadCurrent = is_dir($target);

        if ($hadCurrent && !@rename($target, $previous)) {
            throw new RuntimeException('CURRENT_RELEASE_PARK_FAILED');
        }

        if (@rename($prepared, $target)) {
            if ($hadCurrent && is_dir($previous)) Files::removeTree($previous);
            return;
        }

        $restored = !$hadCurrent;
        if ($hadCurrent && is_dir($previous) && !file_exists($target)) {
            $restored = @rename($previous, $target);
        }

        if (!$restored) throw new RuntimeException('PUBLISH_RENAME_FAILED_RESTORE_FAILED');
        throw new RuntimeException('PUBLISH_RENAME_FAILED_RESTORED');
    }
}
