<?php
declare(strict_types=1);

namespace DigiOps\Security;

use DigiOps\Support\Files;
use RuntimeException;

final class SecretVault
{
    private string $file;
    private string $key;

    public function __construct(?string $file = null)
    {
        $this->file = $file ?: DIGIOPS_PRIVATE_ROOT . '/vault/secrets.json';
        $this->key = $this->loadKey();
    }

    public function put(string $name, string $value): void
    {
        if (!preg_match('/^[a-z0-9._-]{2,80}$/i', $name)) throw new RuntimeException('INVALID_SECRET_NAME');
        $all = Files::readJson($this->file, []);
        $all[$name] = $this->encrypt($value);
        Files::writeJson($this->file, $all);
    }

    public function get(string $name): ?string
    {
        $all = Files::readJson($this->file, []);
        if (!isset($all[$name]) || !is_array($all[$name])) return null;
        return $this->decrypt($all[$name]);
    }

    public function delete(string $name): void
    {
        $all = Files::readJson($this->file, []);
        unset($all[$name]);
        Files::writeJson($this->file, $all);
    }

    public function has(string $name): bool
    {
        return $this->get($name) !== null;
    }

    private function loadKey(): string
    {
        $env = getenv('DIGIOPS_MASTER_KEY');
        if (is_string($env) && $env !== '') {
            $decoded = base64_decode($env, true);
            if ($decoded !== false && strlen($decoded) >= 32) return substr($decoded, 0, 32);
        }
        $file = DIGIOPS_PRIVATE_ROOT . '/vault/master.key';
        if (!is_file($file)) {
            Files::ensureDir(dirname($file), 0700);
            if (file_put_contents($file, base64_encode(random_bytes(32)), LOCK_EX) === false) throw new RuntimeException('MASTER_KEY_CREATE_FAILED');
            @chmod($file, 0600);
        }
        $decoded = base64_decode(trim((string)file_get_contents($file)), true);
        if ($decoded === false || strlen($decoded) < 32) throw new RuntimeException('MASTER_KEY_INVALID');
        return substr($decoded, 0, 32);
    }

    private function encrypt(string $plain): array
    {
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = sodium_crypto_secretbox($plain, $nonce, $this->key);
            return ['v'=>1,'alg'=>'secretbox','nonce'=>base64_encode($nonce),'data'=>base64_encode($cipher)];
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) throw new RuntimeException('ENCRYPT_FAILED');
        return ['v'=>1,'alg'=>'aes-256-gcm','iv'=>base64_encode($iv),'tag'=>base64_encode($tag),'data'=>base64_encode($cipher)];
    }

    private function decrypt(array $record): string
    {
        if (($record['alg'] ?? '') === 'secretbox' && function_exists('sodium_crypto_secretbox_open')) {
            $plain = sodium_crypto_secretbox_open(base64_decode((string)$record['data']), base64_decode((string)$record['nonce']), $this->key);
            if ($plain === false) throw new RuntimeException('DECRYPT_FAILED');
            return $plain;
        }
        if (($record['alg'] ?? '') === 'aes-256-gcm') {
            $plain = openssl_decrypt(
                base64_decode((string)$record['data']),
                'aes-256-gcm',
                $this->key,
                OPENSSL_RAW_DATA,
                base64_decode((string)$record['iv']),
                base64_decode((string)$record['tag'])
            );
            if ($plain === false) throw new RuntimeException('DECRYPT_FAILED');
            return $plain;
        }
        throw new RuntimeException('UNSUPPORTED_SECRET_FORMAT');
    }
}
