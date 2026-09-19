<?php
declare(strict_types=1);

namespace DigiOps\Security;

use DigiOps\Support\Files;

final class RateLimiter
{
    public static function allow(string $bucket, int $limit, int $windowSeconds): bool
    {
        $key = hash('sha256', $bucket);
        $dir = DIGIOPS_PRIVATE_ROOT . '/rate';
        Files::ensureDir($dir);
        $file = $dir . '/' . $key . '.json';
        $now = time();
        $data = Files::readJson($file, ['window' => $now, 'count' => 0]);
        if ($now - (int)($data['window'] ?? 0) >= $windowSeconds) $data = ['window' => $now, 'count' => 0];
        $data['count'] = (int)($data['count'] ?? 0) + 1;
        Files::writeJson($file, $data);
        return $data['count'] <= $limit;
    }
}
