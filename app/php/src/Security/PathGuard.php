<?php
declare(strict_types=1);

namespace DigiOps\Security;

use InvalidArgumentException;

final class PathGuard
{
    public static function slug(string $value): string
    {
        $slug = strtolower(trim($value));
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $slug)) {
            throw new InvalidArgumentException('INVALID_APP_SLUG');
        }
        return $slug;
    }

    public static function publicRelative(string $slug): string
    {
        return 'public_html/' . self::slug($slug) . '/';
    }

    public static function privateRelative(string $slug): string
    {
        return 'private_html/' . self::slug($slug) . '/';
    }

    public static function assertManagedRelative(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if (str_contains($path, '..') || str_starts_with($path, '/')) {
            throw new InvalidArgumentException('UNMANAGED_PATH');
        }
        if (!preg_match('#^(public_html|private_html)/[a-z0-9][a-z0-9-]{0,62}/(?:[A-Za-z0-9._/-]*)$#', $path)) {
            throw new InvalidArgumentException('UNMANAGED_PATH');
        }
        return $path;
    }
}
