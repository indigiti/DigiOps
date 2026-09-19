<?php
declare(strict_types=1);

namespace DigiOps\Security;

use DigiOps\Support\Files;
use InvalidArgumentException;
use RuntimeException;

final class UserStore
{
    private string $file;

    public function __construct(?string $file = null)
    {
        $this->file = $file ?: DIGIOPS_PRIVATE_ROOT . '/users/users.json';
    }

    public function hasUsers(): bool
    {
        return count(Files::readJson($this->file, [])) > 0;
    }

    public function create(string $username, string $name, string $password, string $role = 'admin', ?string $totpSecret = null): array
    {
        $username = strtolower(trim($username));
        if (!preg_match('/^[a-z0-9._-]{3,48}$/', $username)) throw new InvalidArgumentException('INVALID_USERNAME');
        if (strlen($password) < 12) throw new InvalidArgumentException('PASSWORD_TOO_SHORT');
        if (!in_array($role, ['admin','operator','viewer'], true)) throw new InvalidArgumentException('INVALID_ROLE');

        $users = Files::readJson($this->file, []);
        foreach ($users as $user) {
            if (($user['username'] ?? '') === $username) throw new RuntimeException('USER_EXISTS');
        }
        $record = [
            'id' => bin2hex(random_bytes(8)),
            'username' => $username,
            'name' => trim($name) ?: $username,
            'passwordHash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
            'totpSecret' => $totpSecret ? strtoupper(preg_replace('/[^A-Z2-7]/', '', $totpSecret)) : null,
            'enabled' => true,
            'createdAt' => date(DATE_ATOM),
        ];
        $users[] = $record;
        Files::writeJson($this->file, $users);
        unset($record['passwordHash'], $record['totpSecret']);
        return $record;
    }

    public function verify(string $username, string $password, ?string $totp = null): ?array
    {
        $username = strtolower(trim($username));
        foreach (Files::readJson($this->file, []) as $user) {
            if (($user['username'] ?? '') !== $username || !($user['enabled'] ?? false)) continue;
            if (!password_verify($password, (string)($user['passwordHash'] ?? ''))) return null;
            $secret = (string)($user['totpSecret'] ?? '');
            if ($secret !== '' && !Totp::verify($secret, (string)$totp)) return null;
            unset($user['passwordHash'], $user['totpSecret']);
            return $user;
        }
        return null;
    }

    public function all(): array
    {
        $out = [];
        foreach (Files::readJson($this->file, []) as $user) {
            unset($user['passwordHash'], $user['totpSecret']);
            $out[] = $user;
        }
        return $out;
    }
}
