<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use DigiOps\Deploy\DeploymentJobRepository;
use DigiOps\Health\HealthService;
use DigiOps\Security\Session;
use DigiOps\Support\JsonResponse;
use DigiOps\Registry\ProjectRegistry;
use DigiOps\Targets\TargetService;
use DigiOps\Targets\RemoteDeploymentDriver;

Session::requireRole();
$id=(string)($_GET['project']??'');
$requestId=strtolower(trim((string)($_GET['requestId']??'')));
if ($id==='') JsonResponse::send(['error'=>'PROJECT_REQUIRED'],400);
if($requestId!=='' && !preg_match('/^[a-f0-9]{32}$/',$requestId)) JsonResponse::send(['error'=>'REQUEST_ID_INVALID'],400);

try {
    $registry=new ProjectRegistry();
    $project=$registry->find($id);
    if(!$project) throw new RuntimeException('PROJECT_NOT_FOUND');
    $target=(new TargetService())->forProject($id);

    if(($target['id']??'local')==='local'){
        $result=(new HealthService())->probe($id);
    }else{
        $result=(new RemoteDeploymentDriver())->health($id,$project);
        $checkedAt=(string)($result['checkedAt']??date(DATE_ATOM));
        $registry->patchRuntime($id,[
            'health'=>($result['ok']??false)?'healthy':'attention',
            'healthCheckedAt'=>$checkedAt,
        ]);
        $result['checkedAt']=$checkedAt;
    }

    if($requestId!==''){
        try{
            (new DeploymentJobRepository())->patch($requestId,[
                'health'=>[
                    'ok'=>(bool)($result['ok']??false),
                    'checkedAt'=>(string)($result['checkedAt']??date(DATE_ATOM)),
                    'http'=>$result['http']??null,
                ],
                'phase'=>($result['ok']??false)?'health-verified':'health-attention',
            ]);
        }catch(Throwable){}
    }

    JsonResponse::send($result);
}
catch (Throwable $e) { JsonResponse::send(['error'=>$e->getMessage()],400); }
