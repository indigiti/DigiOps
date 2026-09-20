<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use DigiOps\Health\HealthService;
use DigiOps\Security\Session;
use DigiOps\Support\JsonResponse;
use DigiOps\Registry\ProjectRegistry;
use DigiOps\Targets\TargetService;
use DigiOps\Targets\RemoteDeploymentDriver;

Session::requireRole();
$id=(string)($_GET['project']??'');
if ($id==='') JsonResponse::send(['error'=>'PROJECT_REQUIRED'],400);
try {
    $project=(new ProjectRegistry())->find($id);
    if(!$project) throw new RuntimeException('PROJECT_NOT_FOUND');
    $target=(new TargetService())->forProject($id);
    if(($target['id']??'local')==='local') JsonResponse::send((new HealthService())->probe($id));
    $result=(new RemoteDeploymentDriver())->health($id,$project);
    (new ProjectRegistry())->patchRuntime($id,['health'=>($result['ok']??false)?'healthy':'attention']);
    JsonResponse::send($result);
}
catch (Throwable $e) { JsonResponse::send(['error'=>$e->getMessage()],400); }
