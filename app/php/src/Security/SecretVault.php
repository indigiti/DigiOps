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
        $encrypted=$this->encrypt($value);
        Files::mutateJsonStrict($this->file, [], static function(array $all) use ($name,$encrypted): array {
            self::assertStore($all);
            $all[$name]=$encrypted;
            return $all;
        });
    }

    public function get(string $name): ?string
    {
        $all = Files::readJsonStrict($this->file, []);
        self::assertStore($all);
        if (!array_key_exists($name,$all)) return null;
        if (!is_array($all[$name])) throw new RuntimeException('SECRET_RECORD_INVALID');
        return $this->decrypt($all[$name]);
    }

    public function delete(string $name): void
    {
        Files::mutateJsonStrict($this->file, [], static function(array $all) use ($name): array {
            self::assertStore($all);
            unset($all[$name]);
            return $all;
        });
    }

    public function has(string $name): bool
    {
        return $this->get($name) !== null;
    }

    private static function assertStore(array $all): void
    {
        foreach($all as $name=>$record){
            if(!is_string($name) || !is_array($record)) throw new RuntimeException('SECRET_VAULT_INVALID');
        }
    }

    private function loadKey(): string
    {
        $env = getenv('DIGIOPS_MASTER_KEY');
        if (is_string($env) && $env !== '') {
            $decoded = base64_decode($env, true);
            if ($decoded !== false && strlen($decoded) >= 32) return substr($decoded, 0, 32);
        }

        $file = DIGIOPS_PRIVATE_ROOT . '/vault/master.key';
        Files::withLock($file.'.lock', static function() use ($file): void {
            if (is_file($file)) return;
            Files::ensureDir(dirname($file), 0700);
            if (file_put_contents($file, base64_encode(random_bytes(32)), LOCK_EX) === false) {
                throw new RuntimeException('MASTER_KEY_CREATE_FAILED');
            }
            @chmod($file, 0600);
        });

        $raw=file_get_contents($file);
        if($raw===false) throw new RuntimeException('MASTER_KEY_READ_FAILED');
        $decoded = base64_decode(trim($raw), true);
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
            $data=$this->decode((string)($record['data']??''));
            $nonce=$this->decode((string)($record['nonce']??''));
            $plain = sodium_crypto_secretbox_open($data, $nonce, $this->key);
            if ($plain === false) throw new RuntimeException('DECRYPT_FAILED');
            return $plain;
        }
        if (($record['alg'] ?? '') === 'aes-256-gcm') {
            $plain = openssl_decrypt(
                $this->decode((string)($record['data']??'')),
                'aes-256-gcm',
                $this->key,
                OPENSSL_RAW_DATA,
                $this->decode((string)($record['iv']??'')),
                $this->decode((string)($record['tag']??''))
            );
            if ($plain === false) throw new RuntimeException('DECRYPT_FAILED');
            return $plain;
        }
        throw new RuntimeException('UNSUPPORTED_SECRET_FORMAT');
    }

    private function decode(string $value): string
    {
        $decoded=base64_decode($value,true);
        if($decoded===false) throw new RuntimeException('DECRYPT_FAILED');
        return $decoded;
    }
}
