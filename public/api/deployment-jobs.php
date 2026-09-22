<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use DigiOps\Deploy\DeploymentJobRepository;
use DigiOps\Security\Session;
use DigiOps\Support\JsonResponse;

Session::requireRole();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') JsonResponse::send(['error'=>'METHOD_NOT_ALLOWED'],405);

try {
    $jobs=new DeploymentJobRepository();
    $requestId=strtolower(trim((string)($_GET['requestId']??'')));
    $project=trim((string)($_GET['project']??''));
    if($requestId!==''){
        $job=$jobs->get($requestId);
        if(!$job) JsonResponse::send(['error'=>'DEPLOYMENT_JOB_NOT_FOUND'],404);
        JsonResponse::send(['ok'=>true,'job'=>$job]);
    }
    if($project!==''){
        JsonResponse::send([
            'ok'=>true,
            'active'=>$jobs->active($project),
            'latest'=>$jobs->latestForProject($project),
        ]);
    }
    JsonResponse::send([
        'ok'=>true,
        'active'=>$jobs->active(),
        'recent'=>$jobs->all(50),
    ]);
} catch(Throwable $e){
    JsonResponse::send(['error'=>$e->getMessage()],400);
}
