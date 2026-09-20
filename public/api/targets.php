<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use DigiOps\Audit\AuditLog;
use DigiOps\Registry\ProjectRegistry;
use DigiOps\Security\SecretVault;
use DigiOps\Security\Session;
use DigiOps\Support\JsonResponse;
use DigiOps\Targets\TargetRegistry;
use DigiOps\Targets\TargetService;

$user=Session::requireRole();
$registry=new TargetRegistry();
$vault=new SecretVault();
$method=$_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method==='GET') {
        $targets=array_map(static function(array $target) use ($vault): array {
            $id=(string)$target['id'];
            $target['secretSet']=$id==='local' ? true : $vault->has('target.'.$id.'.secret');
            return $target;
        },$registry->all());
        JsonResponse::send(['ok'=>true,'targets'=>$targets]);
    }

    if (($user['role']??'')!=='admin') JsonResponse::send(['error'=>'FORBIDDEN'],403);
    Session::assertCsrf();
    $data=json_decode(file_get_contents('php://input') ?: '',true);
    if(!is_array($data)) JsonResponse::send(['error'=>'INVALID_JSON'],400);
    $action=(string)($data['action']??'');

    if($action==='save'){
        $target=$registry->upsert($data);
        $secret=trim((string)($data['secret']??''));
        if($secret!==''){
            if(strlen($secret)<32) throw new RuntimeException('TARGET_SECRET_TOO_SHORT');
            $vault->put('target.'.$target['id'].'.secret',$secret);
        }
        (new AuditLog())->write('TARGET_UPSERT',[
            'target'=>$target['id'],
            'type'=>$target['type'],
            'endpoint'=>$target['endpoint'],
        ],$user);
        JsonResponse::send(['ok'=>true,'target'=>$target,'secretSet'=>$vault->has('target.'.$target['id'].'.secret')]);
    }

    if($action==='test'){
        $id=(string)($data['id']??'');
        $result=(new TargetService())->test($id);
        (new AuditLog())->write('TARGET_TEST',[
            'target'=>$id,
            'latencyMs'=>$result['_latencyMs']??($result['latencyMs']??null),
            'agentVersion'=>$result['agentVersion']??null,
        ],$user);
        JsonResponse::send(['ok'=>true,'result'=>$result]);
    }

    if($action==='delete'){
        $id=(string)($data['id']??'');
        foreach((new ProjectRegistry())->all() as $project){
            if(($project['targetId']??'local')===$id) throw new RuntimeException('TARGET_IN_USE');
        }
        $registry->delete($id);
        $vault->delete('target.'.$id.'.secret');
        (new AuditLog())->write('TARGET_DELETE',['target'=>$id],$user);
        JsonResponse::send(['ok'=>true]);
    }

    JsonResponse::send(['error'=>'UNKNOWN_ACTION'],400);
} catch(Throwable $e){
    JsonResponse::send(['error'=>$e->getMessage()],400);
}
