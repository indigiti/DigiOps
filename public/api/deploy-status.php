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

$user=Session::requireRole(['admin','operator']);
$projectId=(string)($_GET['project']??'');
$commit=strtolower(trim((string)($_GET['commit']??'')));

if($projectId==='') JsonResponse::send(['error'=>'PROJECT_REQUIRED'],400);
if(!preg_match('/^[a-f0-9]{40}$/',$commit)) JsonResponse::send(['error'=>'COMMIT_REQUIRED'],400);

try {
    $registry=new ProjectRegistry();
    $project=$registry->find($projectId);
    if(!$project) JsonResponse::send(['error'=>'PROJECT_NOT_FOUND'],404);

    $registryCommit=strtolower(trim((string)($project['commit']??'')));
    if($registryCommit===$commit){
        JsonResponse::send([
            'ok'=>true,
            'state'=>'deployed',
            'reconciled'=>false,
            'commit'=>$commit,
            'release'=>(string)($project['release']??''),
            'source'=>'registry',
        ]);
    }

    $target=(new TargetService())->forProject($projectId);
    $releases=($target['id']??'local')==='local'
        ? (new ReleaseManager())->releases($projectId)
        : (new RemoteDeploymentDriver())->releases($projectId);

    $matched=null;
    foreach($releases as $release){
        if(!is_array($release)) continue;
        $releaseCommit=strtolower(trim((string)($release['commit']??'')));
        if($releaseCommit===$commit){
            $matched=$release;
            break;
        }
    }

    if(!$matched){
        JsonResponse::send([
            'ok'=>true,
            'state'=>'pending',
            'reconciled'=>false,
            'commit'=>$commit,
        ]);
    }

    $releaseId=(string)($matched['id']??$matched['release']??'Recovered release');
    $lastDeploy=(string)($matched['createdAt']??date(DATE_ATOM));
    $registry->patchRuntime($projectId,[
        'status'=>'deployed',
        'health'=>'pending',
        'commit'=>$commit,
        'release'=>$releaseId,
        'lastDeploy'=>$lastDeploy,
        'update'=>false,
    ]);

    (new AuditLog())->write('DEPLOY_RECONCILED',[
        'project'=>$projectId,
        'target'=>$target['id']??'local',
        'release'=>$releaseId,
        'commit'=>$commit,
        'source'=>'release-evidence',
    ],$user);

    JsonResponse::send([
        'ok'=>true,
        'state'=>'deployed',
        'reconciled'=>true,
        'commit'=>$commit,
        'release'=>$releaseId,
        'source'=>'release-evidence',
    ]);
} catch(Throwable $e){
    JsonResponse::send(['error'=>$e->getMessage()],400);
}
