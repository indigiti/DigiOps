<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

@set_time_limit(90);
header('Cache-Control: no-store, private');

use DigiOps\Audit\AuditLog;
use DigiOps\Deploy\ReleaseManager;
use DigiOps\Registry\ProjectRegistry;
use DigiOps\Security\Session;
use DigiOps\Support\JsonResponse;
use DigiOps\Targets\RemoteDeploymentDriver;
use DigiOps\Targets\TargetService;

if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST') JsonResponse::send(['error'=>'METHOD_NOT_ALLOWED'],405);
$user=Session::requireRole(['admin','operator']);
Session::assertCsrf();
// Do not hold the PHP session mutex while probing the remote target.
// This endpoint must be able to run while deploy.php is still executing.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$data=json_decode(file_get_contents('php://input') ?: '',true);
if(!is_array($data)) JsonResponse::send(['error'=>'INVALID_JSON'],400);
$projectId=(string)($data['project']??'');
$commit=strtolower(trim((string)($data['commit']??'')));

if($projectId==='') JsonResponse::send(['error'=>'PROJECT_REQUIRED'],400);
if(!preg_match('/^[a-f0-9]{40}$/',$commit)) JsonResponse::send(['error'=>'COMMIT_REQUIRED'],400);

try {
    $registry=new ProjectRegistry();
    $project=$registry->find($projectId);
    if(!$project) JsonResponse::send(['error'=>'PROJECT_NOT_FOUND'],404);

    $registryCommit=strtolower(trim((string)($project['commit']??'')));
    if($registryCommit===$commit){
        JsonResponse::send([
            'ok'=>true,'state'=>'deployed','reconciled'=>false,
            'commit'=>$commit,
            'release'=>(string)($project['release']??''),
            'source'=>'registry',
        ]);
    }

    $targets=new TargetService();
    $target=$targets->forProject($projectId);
    if(($target['id']??'local')==='local'){
        $deployment=(new ReleaseManager())->deploymentState($projectId);
        $deploymentCommit=strtolower(trim((string)($deployment['commit']??'')));
        if($deploymentCommit===$commit){
            $state=strtolower(trim((string)($deployment['state']??'')));
            if($state==='deployed'){
                JsonResponse::send([
                    'ok'=>true,'state'=>'deployed','reconciled'=>true,
                    'commit'=>$commit,
                    'release'=>(string)($deployment['release']??''),
                    'source'=>'local-progress',
                ]);
            }
            if($state==='failed'){
                JsonResponse::send([
                    'ok'=>false,'state'=>'failed','reconciled'=>false,
                    'commit'=>$commit,
                    'phase'=>(string)($deployment['phase']??'failed'),
                    'progress'=>(int)($deployment['progress']??100),
                    'error'=>(string)($deployment['error']??'LOCAL_DEPLOY_FAILED'),
                    'updatedAt'=>(string)($deployment['updatedAt']??''),
                    'source'=>'local-progress',
                ]);
            }
            if($state==='running'){
                JsonResponse::send([
                    'ok'=>true,'state'=>'running','reconciled'=>false,
                    'commit'=>$commit,
                    'phase'=>(string)($deployment['phase']??'publishing'),
                    'progress'=>(int)($deployment['progress']??0),
                    'startedAt'=>(string)($deployment['startedAt']??''),
                    'updatedAt'=>(string)($deployment['updatedAt']??''),
                    'release'=>(string)($deployment['release']??''),
                    'source'=>'local-progress',
                ]);
            }
        }
        JsonResponse::send([
            'ok'=>true,'state'=>'pending','reconciled'=>false,
            'commit'=>$commit,'source'=>'local-progress-wait',
        ]);
    }

    // New agents expose current.json, which is written only after the public
    // directory has been atomically published. This is authoritative evidence.
    $agentStatusSupported=false;
    try {
        $agentStatusSupported=true;
        $remote=$targets->remoteRequest($projectId,'deployment-status',['project'=>$projectId]);
        $current=is_array($remote['current']??null)?$remote['current']:[];
        $currentCommit=strtolower(trim((string)($current['commit']??'')));
        if($currentCommit===$commit){
            $releaseId=(string)($current['release']??'Recovered release');
            $lastDeploy=(string)($current['lastDeploy']??date(DATE_ATOM));
            $registry->patchRuntime($projectId,[
                'status'=>'deployed','health'=>'pending','commit'=>$commit,
                'release'=>$releaseId,'lastDeploy'=>$lastDeploy,'update'=>false,
            ]);
            (new AuditLog())->write('DEPLOY_RECONCILED',[
                'project'=>$projectId,'target'=>$target['id']??'',
                'release'=>$releaseId,'commit'=>$commit,'source'=>'agent-current',
            ],$user);
            JsonResponse::send([
                'ok'=>true,'state'=>'deployed','reconciled'=>true,
                'commit'=>$commit,'release'=>$releaseId,'source'=>'agent-current',
            ]);
        }

        // Agent v1.3+ exposes the in-flight deployment state separately from
        // current.json. current.json remains authoritative for completion, while
        // this state prevents a long publish from being misclassified as failed.
        $deployment=is_array($remote['deployment']??null)?$remote['deployment']:[];
        $deploymentCommit=strtolower(trim((string)($deployment['commit']??'')));
        if($deploymentCommit===$commit){
            $remoteState=strtolower(trim((string)($deployment['state']??'')));
            if(in_array($remoteState,['uploading','running'],true)){
                JsonResponse::send([
                    'ok'=>true,'state'=>'running','reconciled'=>false,
                    'commit'=>$commit,
                    'phase'=>(string)($deployment['phase']??'publishing'),
                    'progress'=>(int)($deployment['progress']??0),
                    'startedAt'=>(string)($deployment['startedAt']??''),
                    'updatedAt'=>(string)($deployment['updatedAt']??''),
                    'release'=>(string)($deployment['release']??''),
                    'source'=>'agent-progress',
                ]);
            }
            if($remoteState==='failed'){
                JsonResponse::send([
                    'ok'=>false,'state'=>'failed','reconciled'=>false,
                    'commit'=>$commit,
                    'phase'=>(string)($deployment['phase']??'failed'),
                    'progress'=>(int)($deployment['progress']??100),
                    'error'=>(string)($deployment['error']??'REMOTE_DEPLOY_FAILED'),
                    'updatedAt'=>(string)($deployment['updatedAt']??''),
                    'source'=>'agent-progress',
                ]);
            }
        }
    } catch(Throwable $statusError) {
        $agentStatusSupported=false;
        // Older agents do not know deployment-status. Only those agents may use
        // the conservative release+health compatibility check below.
    }

    if($agentStatusSupported){
        JsonResponse::send([
            'ok'=>true,'state'=>'pending','reconciled'=>false,
            'commit'=>$commit,'source'=>'agent-current-wait',
        ]);
    }

    $driver=new RemoteDeploymentDriver();
    $releases=$driver->releases($projectId);
    $matched=null;
    foreach($releases as $release){
        if(!is_array($release)) continue;
        if(strtolower(trim((string)($release['commit']??'')))===$commit){
            $matched=$release;
            break;
        }
    }

    if(!$matched){
        JsonResponse::send([
            'ok'=>true,'state'=>'pending','reconciled'=>false,
            'commit'=>$commit,'source'=>'remote-release-list',
        ]);
    }

    // Compatibility mode for existing agents: do not trust a release directory
    // immediately because its metadata is created before the final atomic rename.
    $createdAt=(string)($matched['createdAt']??'');
    $createdTs=$createdAt!=='' ? strtotime($createdAt) : false;
    $oldEnough=is_int($createdTs) && $createdTs <= time()-10;
    $healthy=false;
    if($oldEnough){
        try {$healthy=(bool)(($driver->health($projectId,$project)['ok']??false));}
        catch(Throwable) {$healthy=false;}
    }

    if(!$oldEnough || !$healthy){
        JsonResponse::send([
            'ok'=>true,'state'=>'pending','reconciled'=>false,
            'commit'=>$commit,'source'=>'release-evidence-wait',
        ]);
    }

    $releaseId=(string)($matched['id']??$matched['release']??'Recovered release');
    $lastDeploy=$createdAt!==''?$createdAt:date(DATE_ATOM);
    $registry->patchRuntime($projectId,[
        'status'=>'deployed','health'=>'pending','commit'=>$commit,
        'release'=>$releaseId,'lastDeploy'=>$lastDeploy,'update'=>false,
    ]);
    (new AuditLog())->write('DEPLOY_RECONCILED',[
        'project'=>$projectId,'target'=>$target['id']??'',
        'release'=>$releaseId,'commit'=>$commit,'source'=>'release-health-compat',
    ],$user);

    JsonResponse::send([
        'ok'=>true,'state'=>'deployed','reconciled'=>true,
        'commit'=>$commit,'release'=>$releaseId,'source'=>'release-health-compat',
    ]);
} catch(Throwable $e){
    JsonResponse::send(['error'=>$e->getMessage()],400);
}
