<?php
declare(strict_types=1);
namespace DigiOps\KrushiWorld;

use RuntimeException;

/**
 * Deliberately narrow local-Cloudways provisioning surface.
 * The authenticated DigiOps administrator, not an unauthenticated app visitor,
 * authorizes secret configuration and one-use KrushiPulse owner enrollment.
 */
final class SetupManager {
    public function __construct(private string $storage) {}

    private function guard(): void {
        if (is_link($this->storage)) throw new RuntimeException('UNSAFE_STORAGE');
        if (!is_dir($this->storage)) {
            if (!is_dir(dirname($this->storage)) || !mkdir($this->storage,0700) && !is_dir($this->storage)) {
                throw new RuntimeException('STORAGE_UNAVAILABLE');
            }
        }
        if (!is_dir($this->storage)) throw new RuntimeException('STORAGE_UNAVAILABLE');
        $storage=realpath($this->storage);
        if ($storage===false) throw new RuntimeException('STORAGE_UNAVAILABLE');
        foreach (['config','auth'] as $folder) {
            $path=$storage.'/'.$folder;
            if (is_link($path)) throw new RuntimeException('UNSAFE_STORAGE');
            if (!is_dir($path) && !mkdir($path,0700) && !is_dir($path)) throw new RuntimeException('STORAGE_UNAVAILABLE');
            if (!is_dir($path) || is_link($path)) throw new RuntimeException('UNSAFE_STORAGE');
        }
    }
    private function path(string $name): string {
        $this->guard();
        return $this->storage.'/'.$name;
    }
    private function readSecret(string $path): array {
        if (is_link($path) || (file_exists($path) && !is_file($path))) throw new RuntimeException('UNSAFE_STORAGE');
        if (!is_file($path)) return [];
        $perms=fileperms($path);
        if ($perms===false || ($perms & 0077)!==0) throw new RuntimeException('INSECURE_FILE_PERMISSIONS');
        $raw=file_get_contents($path);
        if (!is_string($raw) || strlen($raw)>16384) throw new RuntimeException('INVALID_SETTINGS');
        try { $data=json_decode($raw,true,16,JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new RuntimeException('INVALID_SETTINGS'); }
        if (!is_array($data) || array_is_list($data)) throw new RuntimeException('INVALID_SETTINGS');
        return $data;
    }
    private function writeSecret(string $path,array $data): void {
        if (is_link($path)) throw new RuntimeException('UNSAFE_STORAGE');
        $dir=dirname($path);
        $tmp=tempnam($dir,'.kp-secret-');
        if (!is_string($tmp)) throw new RuntimeException('PRIVATE_WRITE_FAILED');
        try {
            if (!chmod($tmp,0600)) throw new RuntimeException('PRIVATE_WRITE_FAILED');
            $payload=json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
            if (file_put_contents($tmp,$payload,LOCK_EX)!==strlen($payload)) throw new RuntimeException('PRIVATE_WRITE_FAILED');
            if (is_link($path) || !rename($tmp,$path)) throw new RuntimeException('PRIVATE_WRITE_FAILED');
        } finally { if (is_file($tmp)) unlink($tmp); }
    }
    private function exclusive(string $target, callable $work): mixed {
        $lock=$target.'.lock';
        if (is_link($lock)) throw new RuntimeException('UNSAFE_STORAGE');
        $f=fopen($lock,'c');
        if (!$f || !flock($f,LOCK_EX)) throw new RuntimeException('PRIVATE_LOCK_FAILED');
        @chmod($lock,0600);
        try{return $work();}finally{flock($f,LOCK_UN);fclose($f);}
    }
    public function status(): array {
        $cfg=$this->readSecret($this->path('config/runtime.json'));
        return [
            'mode'=>$cfg['APMC_AUTH_MODE']??'unset',
            'origin'=>$cfg['APMC_PUBLIC_ORIGIN']??'unset',
            'keyConfigured'=>!empty($cfg['APMC_DATA_GOV_IN_API_KEY']),
            'authKeyConfigured'=>!empty($cfg['APMC_AUTH_KEY']),
            'storagePresent'=>true,
        ];
    }
    public function configure(string $apiKey): array {
        $target=$this->path('config/runtime.json');
        if ($apiKey!=='' && !preg_match('/^[A-Za-z0-9._-]{16,256}$/D',$apiKey)) throw new RuntimeException('INVALID_AGMARKNET_KEY_FORMAT');
        $this->exclusive($target,function()use($target,$apiKey){
            $cfg=$this->readSecret($target);
            if (($cfg['APMC_AUTH_MODE']??'')==='production') throw new RuntimeException('PRODUCTION_MODE_LOCKED');
            $cfg['APMC_AUTH_MODE']='staging';
            $cfg['APMC_PUBLIC_ORIGIN']='https://stage.digiti.in';
            if (empty($cfg['APMC_AUTH_KEY'])) $cfg['APMC_AUTH_KEY']=bin2hex(random_bytes(32));
            if ($apiKey!=='')$cfg['APMC_DATA_GOV_IN_API_KEY']=$apiKey;
            if (!isset($cfg['APMC_NATIONAL_INTERVAL_HOURS']))$cfg['APMC_NATIONAL_INTERVAL_HOURS']='6';
            $this->writeSecret($target,$cfg);
        });
        return $this->status();
    }
    public function issueGrant(): string {
        $cfg=$this->readSecret($this->path('config/runtime.json'));
        if (($cfg['APMC_AUTH_MODE']??'')!=='staging' || empty($cfg['APMC_AUTH_KEY']))
            throw new RuntimeException('CONFIGURE_STAGING_FIRST');
        $target=$this->path('auth/web-bootstrap.json');
        return $this->exclusive($target,function()use($target){
            $token=bin2hex(random_bytes(32));
            $this->writeSecret($target,[
                'sha256'=>hash('sha256',$token),
                'used'=>false,
                'expires_at'=>time()+900
            ]);
            return $token;
        });
    }
}
