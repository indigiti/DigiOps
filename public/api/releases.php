<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use DigiOps\Deploy\ReleaseManager;
use DigiOps\Security\Session;
use DigiOps\Support\JsonResponse;
use DigiOps\Targets\TargetService;
use DigiOps\Targets\RemoteDeploymentDriver;

Session::requireRole();
$id=(string)($_GET['project']??'');
if ($id==='') JsonResponse::send(['error'=>'PROJECT_REQUIRED'],400);
try {
    $target=(new TargetService())->forProject($id);
    $releases=($target['id']??'local')==='local'
        ? (new ReleaseManager())->releases($id)
        : (new RemoteDeploymentDriver())->releases($id);
    JsonResponse::send(['ok'=>true,'releases'=>$releases]);
}
catch (Throwable $e) { JsonResponse::send(['error'=>$e->getMessage()],400); }
