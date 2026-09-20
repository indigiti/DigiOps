<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use DigiOps\Files\ManagedFileBrowser;
use DigiOps\Security\Session;
use DigiOps\Support\JsonResponse;
use DigiOps\Registry\ProjectRegistry;
use DigiOps\Targets\TargetService;
use DigiOps\Targets\RemoteDeploymentDriver;

Session::requireRole();
$id=(string)($_GET['project']??'');
$scope=(string)($_GET['scope']??'public');
$path=(string)($_GET['path']??'');
if (!in_array($scope,['public','private'],true)) JsonResponse::send(['error'=>'INVALID_SCOPE'],400);
try {
    $project=(new ProjectRegistry())->find($id);
    if(!$project) throw new RuntimeException('PROJECT_NOT_FOUND');
    $target=(new TargetService())->forProject($id);
    $listing=($target['id']??'local')==='local'
        ? (new ManagedFileBrowser())->list($id,$scope,$path)
        : (new RemoteDeploymentDriver())->files($id,$project,$scope,$path);
    JsonResponse::send(['ok'=>true,'listing'=>$listing]);
}
catch (Throwable $e) { JsonResponse::send(['error'=>$e->getMessage()],400); }
