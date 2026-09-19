<?php
declare(strict_types=1);

namespace DigiOps\Audit;

use DigiOps\Support\Files;

final class AuditLog
{
    private string $file;

    public function __construct(?string $file = null)
    {
        $this->file = $file ?: DIGIOPS_PRIVATE_ROOT . '/audit/audit.ndjson';
    }

    public function write(string $event, array $context = [], ?array $user = null): void
    {
        Files::ensureDir(dirname($this->file));
        $previousHash = '';
        if (is_file($this->file) && filesize($this->file) > 0) {
            $lines = file($this->file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $last = json_decode((string)end($lines), true);
            $previousHash = is_array($last) ? (string)($last['hash'] ?? '') : '';
        }
        $entry = [
            'time' => date(DATE_ATOM),
            'event' => $event,
            'actor' => $user['username'] ?? 'system',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'context' => $context,
            'previousHash' => $previousHash,
        ];
        $canonical = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $entry['hash'] = hash('sha256', $previousHash . '|' . $canonical);
        file_put_contents($this->file, json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
        @chmod($this->file, 0640);
    }

    public function recent(int $limit = 100): array
    {
        if (!is_file($this->file)) return [];
        $lines = file($this->file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $out = [];
        foreach (array_slice(array_reverse($lines), 0, max(1, min($limit, 500))) as $line) {
            $row = json_decode($line, true);
            if (is_array($row)) $out[] = $row;
        }
        return $out;
    }
}
