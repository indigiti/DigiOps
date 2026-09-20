<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use DigiOps\Deploy\ReleaseManager;
use DigiOps\Security\Session;
use DigiOps\Support\JsonResponse;
use DigiOps\Registry\ProjectRegistry;
use DigiOps\Targets\TargetService;
use DigiOps\Targets\RemoteDeploymentDriver;

if ($_SERVER['REQUEST_METHOD']!=='POST') JsonResponse::send(['error'=>'METHOD_NOT_ALLOWED'],405);
$user=Session::requireRole(['admin','operator']);
Session::assertCsrf();
$data=json_decode(file_get_contents('php://input') ?: '',true);
if (!is_array($data)) JsonResponse::send(['error'=>'INVALID_JSON'],400);
try {
    $projectId=(string)($data['project']??'');
    $release=(string)($data['release']??'');
    $project=(new ProjectRegistry())->find($projectId);
    if(!$project) throw new RuntimeException('PROJECT_NOT_FOUND');
    $target=(new TargetService())->forProject($projectId);
    if(($target['id']??'local')==='local'){
        JsonResponse::send((new ReleaseManager())->rollback($projectId,$release,$user));
    }
    $result=(new RemoteDeploymentDriver())->rollback($projectId,$project,$release);
    (new ProjectRegistry())->patchRuntime($projectId,[
        'status'=>'deployed',
        'health'=>'pending',
        'release'=>$release,
        'commit'=>(string)($result['commit']??'—'),
        'lastDeploy'=>date(DATE_ATOM),
    ]);
    JsonResponse::send(['ok'=>true]+$result);
}
catch (Throwable $e) { JsonResponse::send(['error'=>$e->getMessage()],400); }
