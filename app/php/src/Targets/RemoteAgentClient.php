<?php
declare(strict_types=1);

namespace DigiOps\Targets;

use DigiOps\Security\SecretVault;
use RuntimeException;

final class RemoteAgentClient
{
    public function __construct(private SecretVault $vault = new SecretVault()) {}

    public function request(array $target,string $action,array $payload=[]): array
    {
        if(($target['type']??'')!=='agent') throw new RuntimeException('TARGET_NOT_REMOTE_AGENT');
        $id=(string)$target['id'];
        $secret=$this->vault->get('target.'.$id.'.secret');
        if(!$secret) throw new RuntimeException('TARGET_SECRET_MISSING');

        $timestamp=(string)time();
        $nonce=bin2hex(random_bytes(16));
        $body=json_encode([
            'action'=>$action,
            'payload'=>$payload,
        ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if($body===false) throw new RuntimeException('TARGET_JSON_FAILED');

        $signature=hash_hmac('sha256',$timestamp."\n".$nonce."\n".$body,$secret);
        $url=rtrim((string)$target['endpoint'],'/');

        $started=microtime(true);
        $timeout=match($action){
            'deploy-commit'=>300,
            'rollback'=>180,
            'deploy-chunk'=>45,
            default=>30,
        };
        $ch=curl_init($url);
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>$body,
            CURLOPT_CONNECTTIMEOUT=>5,
            CURLOPT_TIMEOUT=>$timeout,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_HTTPHEADER=>[
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: DigiOps-ControlPlane/1.2',
                'X-DigiOps-Timestamp: '.$timestamp,
                'X-DigiOps-Nonce: '.$nonce,
                'X-DigiOps-Signature: '.$signature,
            ],
        ]);
        $raw=curl_exec($ch);
        $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
        $errno=curl_errno($ch);
        $error=curl_error($ch);
        curl_close($ch);

        if($raw===false) throw new RuntimeException('TARGET_CONNECT_FAILED_'.$errno.($error?':'.$error:''));
        $data=json_decode((string)$raw,true);
        if(!is_array($data)){
            $contentType='';
            if(function_exists('curl_getinfo')){$contentType='';}
            $safeType=preg_replace('/[^A-Za-z0-9._+\/-]/','_',trim((string)($status?($status):0)));
            throw new RuntimeException('TARGET_INVALID_RESPONSE_HTTP_'.$status.'_BYTES_'.strlen((string)$raw));
        }
        if($status<200 || $status>=300 || !($data['ok']??false)){
            throw new RuntimeException((string)($data['error']??('TARGET_HTTP_'.$status)));
        }
        $data['_latencyMs']=round((microtime(true)-$started)*1000,1);
        return $data;
    }

    public function test(array $target): array
    {
        return $this->request($target,'ping',[]);
    }
}
