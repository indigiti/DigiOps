<?php
declare(strict_types=1);

namespace DigiOps\Targets;

use DigiOps\Security\PathGuard;
use DigiOps\Support\Files;
use InvalidArgumentException;
use RuntimeException;

final class TargetRegistry
{
    private string $file;

    public function __construct(?string $file = null)
    {
        $this->file = $file ?: DIGIOPS_PRIVATE_ROOT . '/registry/targets.json';
    }

    public function all(): array
    {
        return $this->allUnlocked();
    }

    public function find(string $id): ?array
    {
        $id=PathGuard::slug($id);
        foreach($this->allUnlocked() as $target) if($target['id']===$id) return $target;
        return null;
    }

    public function upsert(array $input): array
    {
        $record=$this->normalize($input);
        if ($record['id']==='local') throw new RuntimeException('LOCAL_TARGET_IMMUTABLE');

        Files::withLock($this->file . '.lock', function() use ($record): void {
            $all=array_values(array_filter($this->allUnlocked(),fn(array $t):bool=>$t['id']!=='local'));
            $found=false;
            foreach($all as $i=>$target){
                if($target['id']===$record['id']){
                    $all[$i]=array_merge($target,$record);
                    $found=true;
                    break;
                }
            }
            if(!$found)$all[]=$record;
            Files::writeJson($this->file,$all);
        });

        return $record;
    }

    public function patchRuntime(string $id,array $patch): array
    {
        $id=PathGuard::slug($id);
        if($id==='local') return $this->find('local') ?? throw new RuntimeException('TARGET_NOT_FOUND');

        return Files::withLock($this->file . '.lock', function() use ($id,$patch): array {
            $all=array_values(array_filter($this->allUnlocked(),fn(array $t):bool=>$t['id']!=='local'));
            $updated=null;
            foreach($all as $i=>$target){
                if($target['id']!==$id) continue;
                $updated=$this->normalize(array_merge(
                    $target,
                    array_intersect_key($patch,array_flip(['status','capabilities','lastVerified','latencyMs','agentVersion']))
                ));
                $all[$i]=$updated;
                break;
            }
            if($updated===null) throw new RuntimeException('TARGET_NOT_FOUND');
            Files::writeJson($this->file,$all);
            return $updated;
        });
    }

    public function delete(string $id): void
    {
        $id=PathGuard::slug($id);
        if($id==='local') throw new RuntimeException('LOCAL_TARGET_IMMUTABLE');
        Files::withLock($this->file . '.lock', function() use ($id): void {
            $all=array_values(array_filter($this->allUnlocked(),fn(array $t):bool=>$t['id']!=='local' && $t['id']!==$id));
            Files::writeJson($this->file,$all);
        });
    }

    private function allUnlocked(): array
    {
        $saved = Files::readJsonStrict($this->file, []);
        $targets = [[
            'id'=>'local',
            'name'=>'This Cloudways application',
            'type'=>'local',
            'status'=>'connected',
            'endpoint'=>'',
            'publicBase'=>'public_html',
            'privateBase'=>'private_html',
            'capabilities'=>['deploy','rollback','health','releases','files'],
            'lastVerified'=>null,
        ]];
        foreach ($saved as $target) {
            if (!is_array($target)) throw new RuntimeException('TARGET_REGISTRY_INVALID');
            try {
                $record=$this->normalize($target);
                if ($record['id']!=='local') $targets[]=$record;
            } catch (\Throwable $e) {
                throw new RuntimeException('TARGET_REGISTRY_INVALID',0,$e);
            }
        }
        return $targets;
    }

    private function normalize(array $input): array
    {
        $id=PathGuard::slug((string)($input['id']??$input['name']??''));
        $type=(string)($input['type']??'agent');
        if(!in_array($type,['agent'],true)) throw new InvalidArgumentException('INVALID_TARGET_TYPE');

        $endpoint=rtrim(trim((string)($input['endpoint']??'')),'/');
        if($id!=='local' && ($endpoint==='' || !preg_match('#^https://#i',$endpoint))) throw new InvalidArgumentException('TARGET_HTTPS_ENDPOINT_REQUIRED');

        $publicBase=trim(str_replace('\\','/',(string)($input['publicBase']??'public_html')),'/');
        $privateBase=trim(str_replace('\\','/',(string)($input['privateBase']??'private_html')),'/');
        foreach([$publicBase,$privateBase] as $base){
            if($base==='' || str_contains($base,'..') || !preg_match('#^[A-Za-z0-9._/-]+$#',$base)) throw new InvalidArgumentException('INVALID_TARGET_BASE_PATH');
        }

        return [
            'id'=>$id,
            'name'=>trim((string)($input['name']??$id)) ?: $id,
            'type'=>$id==='local'?'local':$type,
            'status'=>(string)($input['status']??($id==='local'?'connected':'unverified')),
            'endpoint'=>$endpoint,
            'publicBase'=>$publicBase,
            'privateBase'=>$privateBase,
            'capabilities'=>array_values(array_filter((array)($input['capabilities']??[]),'is_string')),
            'lastVerified'=>$input['lastVerified']??null,
            'latencyMs'=>isset($input['latencyMs'])?(float)$input['latencyMs']:null,
            'agentVersion'=>(string)($input['agentVersion']??''),
        ];
    }
}
