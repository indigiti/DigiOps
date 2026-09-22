<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

@set_time_limit(20);
header('Cache-Control: no-store, private');

use DigiOps\Audit\AuditLog;
use DigiOps\Deploy\DeploymentJobRepository;
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
$requestId=strtolower(trim((string)($data['requestId']??'')));
if($requestId!=='' && !preg_match('/^[a-f0-9]{32}$/',$requestId)) JsonResponse::send(['error'=>'REQUEST_ID_INVALID'],400);

if($projectId==='') JsonResponse::send(['error'=>'PROJECT_REQUIRED'],400);
if(!preg_match('/^[a-f0-9]{40}$/',$commit)) JsonResponse::send(['error'=>'COMMIT_REQUIRED'],400);

$jobs=new DeploymentJobRepository();
$reply=static function(array $payload,int $http=200) use ($jobs,$requestId): never {
    if($requestId!=='' && isset($payload['state'])){
        $state=(string)$payload['state'];
        $patch=['state'=>$state];
        if(isset($payload['phase']))$patch['phase']=(string)$payload['phase'];
        elseif($state==='deployed')$patch['phase']='complete';
        if(isset($payload['progress']))$patch['progress']=(int)$payload['progress'];
        elseif(in_array($state,['deployed','failed'],true))$patch['progress']=100;
        if(isset($payload['release']))$patch['release']=(string)$payload['release'];
        if(isset($payload['error']))$patch['error']=(string)$payload['error'];
        if(isset($payload['source']))$patch['verificationSource']=(string)$payload['source'];
        if(in_array($state,['deployed','failed'],true))$patch['completedAt']=date(DATE_ATOM);
        try{$jobs->patch($requestId,$patch);}catch(Throwable){}
    }
    JsonResponse::send($payload,$http);
};

try {
    $registry=new ProjectRegistry();
    $project=$registry->find($projectId);
    if(!$project) $reply(['error'=>'PROJECT_NOT_FOUND'],404);

    $registryCommit=strtolower(trim((string)($project['commit']??'')));
    if($requestId==='' && $registryCommit===$commit){
        $reply([
            'ok'=>true,'state'=>'deployed','reconciled'=>false,
            'commit'=>$commit,'requestId'=>$requestId,
            'release'=>(string)($project['release']??''),
            'source'=>'registry',
        ]);
    }

    $targets=new TargetService();
    $target=$targets->forProject($projectId);
    if(($target['id']??'local')==='local'){
        $deployment=(new ReleaseManager())->deploymentState($projectId);
        $deploymentCommit=strtolower(trim((string)($deployment['commit']??'')));
        $deploymentRequestId=strtolower(trim((string)($deployment['requestId']??'')));
        $deploymentMatches=$deploymentCommit===$commit && ($requestId==='' || ($deploymentRequestId!=='' && hash_equals($deploymentRequestId,$requestId)));
        if($deploymentMatches){
            $state=strtolower(trim((string)($deployment['state']??'')));
            if($state==='deployed'){
                $reply([
                    'ok'=>true,'state'=>'deployed','reconciled'=>true,
                    'commit'=>$commit,'requestId'=>$requestId,
                    'release'=>(string)($deployment['release']??''),
                    'source'=>'local-progress',
                ]);
            }
            if($state==='failed'){
                $reply([
                    'ok'=>false,'state'=>'failed','reconciled'=>false,
                    'commit'=>$commit,'requestId'=>$requestId,
                    'phase'=>(string)($deployment['phase']??'failed'),
                    'progress'=>(int)($deployment['progress']??100),
                    'error'=>(string)($deployment['error']??'LOCAL_DEPLOY_FAILED'),
                    'updatedAt'=>(string)($deployment['updatedAt']??''),
                    'source'=>'local-progress',
                ]);
            }
            if($state==='running'){
                $reply([
                    'ok'=>true,'state'=>'running','reconciled'=>false,
                    'commit'=>$commit,'requestId'=>$requestId,
                    'phase'=>(string)($deployment['phase']??'publishing'),
                    'progress'=>(int)($deployment['progress']??0),
                    'startedAt'=>(string)($deployment['startedAt']??''),
                    'updatedAt'=>(string)($deployment['updatedAt']??''),
                    'release'=>(string)($deployment['release']??''),
                    'source'=>'local-progress',
                ]);
            }
        }
        $reply([
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
        $currentRequestId=strtolower(trim((string)($current['requestId']??'')));
        $currentMatches=$currentCommit===$commit && ($requestId==='' || ($currentRequestId!=='' && hash_equals($currentRequestId,$requestId)));
        if($currentMatches){
            $releaseId=(string)($current['release']??'Recovered release');
            $lastDeploy=(string)($current['lastDeploy']??date(DATE_ATOM));
            $registry->patchRuntime($projectId,[
                'status'=>'deployed','health'=>'pending','commit'=>$commit,'requestId'=>$requestId,
                'release'=>$releaseId,'lastDeploy'=>$lastDeploy,'update'=>false,
            ]);
            (new AuditLog())->write('DEPLOY_RECONCILED',[
                'project'=>$projectId,'target'=>$target['id']??'',
                'release'=>$releaseId,'commit'=>$commit,'source'=>'agent-current',
            ],$user);
            $reply([
                'ok'=>true,'state'=>'deployed','reconciled'=>true,
                'commit'=>$commit,'release'=>$releaseId,'source'=>'agent-current',
            ]);
        }

        // Agent v1.3+ exposes the in-flight deployment state separately from
        // current.json. current.json remains authoritative for completion, while
        // this state prevents a long publish from being misclassified as failed.
        $deployment=is_array($remote['deployment']??null)?$remote['deployment']:[];
        $deploymentCommit=strtolower(trim((string)($deployment['commit']??'')));
        $deploymentRequestId=strtolower(trim((string)($deployment['requestId']??'')));
        $deploymentMatches=$deploymentCommit===$commit && ($requestId==='' || ($deploymentRequestId!=='' && hash_equals($deploymentRequestId,$requestId)));
        if($deploymentMatches){
            $remoteState=strtolower(trim((string)($deployment['state']??'')));
            if(in_array($remoteState,['uploading','running'],true)){
                $reply([
                    'ok'=>true,'state'=>'running','reconciled'=>false,
                    'commit'=>$commit,'requestId'=>$requestId,
                    'phase'=>(string)($deployment['phase']??'publishing'),
                    'progress'=>(int)($deployment['progress']??0),
                    'startedAt'=>(string)($deployment['startedAt']??''),
                    'updatedAt'=>(string)($deployment['updatedAt']??''),
                    'release'=>(string)($deployment['release']??''),
                    'source'=>'agent-progress',
                ]);
            }
            if($remoteState==='failed'){
                $reply([
                    'ok'=>false,'state'=>'failed','reconciled'=>false,
                    'commit'=>$commit,'requestId'=>$requestId,
                    'phase'=>(string)($deployment['phase']??'failed'),
                    'progress'=>(int)($deployment['progress']??100),
                    'error'=>(string)($deployment['error']??'REMOTE_DEPLOY_FAILED'),
                    'updatedAt'=>(string)($deployment['updatedAt']??''),
                    'source'=>'agent-progress',
                ]);
            }
        }
    } catch(Throwable $statusError) {
        if($requestId!==''){
            $reply([
                'ok'=>true,'state'=>'unavailable','reconciled'=>false,
                'commit'=>$commit,'requestId'=>$requestId,
                'error'=>(string)$statusError->getMessage(),
                'source'=>'agent-status-error',
            ]);
        }
        $agentStatusSupported=false;
        // Only legacy callers without a request ID may use the conservative
        // release+health compatibility check below.
    }

    if($agentStatusSupported){
        $reply([
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
        $reply([
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
        $reply([
            'ok'=>true,'state'=>'pending','reconciled'=>false,
            'commit'=>$commit,'source'=>'release-evidence-wait',
        ]);
    }

    $releaseId=(string)($matched['id']??$matched['release']??'Recovered release');
    $lastDeploy=$createdAt!==''?$createdAt:date(DATE_ATOM);
    $registry->patchRuntime($projectId,[
        'status'=>'deployed','health'=>'pending','commit'=>$commit,'requestId'=>$requestId,
        'release'=>$releaseId,'lastDeploy'=>$lastDeploy,'update'=>false,
    ]);
    (new AuditLog())->write('DEPLOY_RECONCILED',[
        'project'=>$projectId,'target'=>$target['id']??'',
        'release'=>$releaseId,'commit'=>$commit,'source'=>'release-health-compat',
    ],$user);

    $reply([
        'ok'=>true,'state'=>'deployed','reconciled'=>true,
        'commit'=>$commit,'release'=>$releaseId,'source'=>'release-health-compat',
    ]);
} catch(Throwable $e){
    $reply(['error'=>$e->getMessage()],400);
}
