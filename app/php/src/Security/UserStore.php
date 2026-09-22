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

        $id=bin2hex(random_bytes(8));
        $normalizedTotp=$totpSecret ? strtoupper((string)preg_replace('/[^A-Z2-7]/', '', $totpSecret)) : '';
        if($normalizedTotp!=='') (new SecretVault())->put('user.'.$id.'.totp',$normalizedTotp);

        $record = [
            'id' => $id,
            'username' => $username,
            'name' => trim($name) ?: $username,
            'passwordHash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
            'totpEnabled' => $normalizedTotp!=='',
            'enabled' => true,
            'createdAt' => date(DATE_ATOM),
        ];

        Files::withLock($this->file.'.lock', function() use ($record,$username): void {
            $users=Files::readJson($this->file,[]);
            foreach($users as $user){
                if(($user['username']??'')===$username) throw new RuntimeException('USER_EXISTS');
            }
            $users[]=$record;
            Files::writeJson($this->file,$users);
        });

        $public=$record;
        unset($public['passwordHash']);
        return $public;
    }

    public function verify(string $username, string $password, ?string $totp = null): ?array
    {
        $username = strtolower(trim($username));
        foreach (Files::readJson($this->file, []) as $user) {
            if (($user['username'] ?? '') !== $username || !($user['enabled'] ?? false)) continue;
            if (!password_verify($password, (string)($user['passwordHash'] ?? ''))) return null;

            $id=(string)($user['id']??'');
            $legacy=(string)($user['totpSecret']??'');
            $secret=$legacy;
            if($secret==='' && ($user['totpEnabled']??false) && $id!==''){
                try{$secret=(string)((new SecretVault())->get('user.'.$id.'.totp')??'');}
                catch(\Throwable){return null;}
            }
            if ($secret !== '' && !Totp::verify($secret, (string)$totp)) return null;

            if($legacy!=='' && $id!==''){
                try{
                    (new SecretVault())->put('user.'.$id.'.totp',$legacy);
                    $this->removeLegacyTotp($id);
                    $user['totpEnabled']=true;
                    unset($user['totpSecret']);
                }catch(\Throwable){}
            }

            unset($user['passwordHash'], $user['totpSecret']);
            return $user;
        }
        return null;
    }

    public function all(): array
    {
        $out = [];
        foreach (Files::readJson($this->file, []) as $user) {
            if(isset($user['totpSecret']) && !isset($user['totpEnabled']))$user['totpEnabled']=(string)$user['totpSecret']!=='';
            unset($user['passwordHash'], $user['totpSecret']);
            $out[] = $user;
        }
        return $out;
    }

    private function removeLegacyTotp(string $id): void
    {
        Files::withLock($this->file.'.lock', function() use ($id): void {
            $users=Files::readJson($this->file,[]);
            foreach($users as &$row){
                if(($row['id']??'')!==$id)continue;
                unset($row['totpSecret']);
                $row['totpEnabled']=true;
                break;
            }
            unset($row);
            Files::writeJson($this->file,$users);
        });
    }
}
