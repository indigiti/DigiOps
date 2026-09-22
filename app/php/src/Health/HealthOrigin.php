<?php
declare(strict_types=1);

namespace DigiOps\Health;

use DigiOps\Support\Files;
use RuntimeException;

final class HealthOrigin
{
    public static function configured(): string
    {
        $origin=trim((string)(getenv('DIGIOPS_CANONICAL_ORIGIN') ?: getenv('APP_URL') ?: ''));
        if($origin!=='') return self::normalize($origin);

        $file=DIGIOPS_PRIVATE_ROOT . '/config/infrastructure.json';
        if(is_file($file)){
            $config=Files::readJsonStrict($file,[]);
            $stored=trim((string)($config['applicationOrigin']??''));
            if($stored!=='') return self::normalize($stored);
        }

        throw new RuntimeException('HEALTH_ORIGIN_NOT_CONFIGURED');
    }

    public static function normalize(string $origin): string
    {
        $origin=trim($origin);
        if($origin==='' || !filter_var($origin,FILTER_VALIDATE_URL)) throw new RuntimeException('HEALTH_ORIGIN_INVALID');

        $parts=parse_url($origin);
        if(!is_array($parts)) throw new RuntimeException('HEALTH_ORIGIN_INVALID');
        $scheme=strtolower((string)($parts['scheme']??''));
        $host=strtolower((string)($parts['host']??''));
        if($host==='') throw new RuntimeException('HEALTH_ORIGIN_INVALID');
        $local=in_array($host,['localhost','127.0.0.1','::1'],true);
        if($scheme!=='https' && !($local && $scheme==='http')) throw new RuntimeException('HEALTH_ORIGIN_HTTPS_REQUIRED');
        if(isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('HEALTH_ORIGIN_INVALID');
        }
        $path=(string)($parts['path']??'');
        if($path!=='' && $path!=='/') throw new RuntimeException('HEALTH_ORIGIN_PATH_NOT_ALLOWED');

        return rtrim($origin,'/');
    }
}
