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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') JsonResponse::send(['error'=>'METHOD_NOT_ALLOWED'],405);
Session::requireRole(['admin','operator']);
Session::assertCsrf();

$data=json_decode(file_get_contents('php://input') ?: '',true);
if(!is_array($data)) JsonResponse::send(['error'=>'INVALID_JSON'],400);

$id=(string)($data['project']??'');
$requestId=strtolower(trim((string)($data['requestId']??'')));
if ($id==='') JsonResponse::send(['error'=>'PROJECT_REQUIRED'],400);
if($requestId!=='' && !preg_match('/^[a-f0-9]{32}$/',$requestId)) JsonResponse::send(['error'=>'REQUEST_ID_INVALID'],400);

$jobs=new DeploymentJobRepository();
$jobBound=false;
$healthSource='health-api';

try {
    $registry=new ProjectRegistry();
    $project=$registry->find($id);
    if(!$project) throw new RuntimeException('PROJECT_NOT_FOUND');

    if($requestId!==''){
        $job=$jobs->get($requestId);
        if(!$job) JsonResponse::send(['error'=>'DEPLOYMENT_JOB_NOT_FOUND'],404);
        if(($job['project']??'')!==($project['id']??'')) JsonResponse::send(['error'=>'DEPLOYMENT_JOB_PROJECT_MISMATCH'],409);
        $jobBound=true;
    }

    $target=(new TargetService())->forProject($id);
    $healthSource=(($target['id']??'local')==='local'?'local-health':'remote-health');
    if(($target['id']??'local')==='local'){
        $result=(new HealthService())->probe($id);
    }else{
        $result=(new RemoteDeploymentDriver())->health($id,$project);
        $checkedAt=(string)($result['checkedAt']??date(DATE_ATOM));
        $result['checkedAt']=$checkedAt;
        $registry->patchRuntime($id,[
            'health'=>($result['ok']??false)?'healthy':'attention',
            'healthCheckedAt'=>$checkedAt,
            'healthDetail'=>$result,
        ]);
    }

    if($requestId!==''){
        $jobs->patch($requestId,[
            'health'=>[
                'ok'=>(bool)($result['ok']??false),
                'checkedAt'=>(string)($result['checkedAt']??date(DATE_ATOM)),
                'http'=>$result['http']??null,
            ],
            'phase'=>($result['ok']??false)?'health-verified':'health-attention',
            'verificationSource'=>$healthSource,
            'error'=>($result['ok']??false)?'':('HEALTH_CHECK_FAILED'.(isset($result['http']['status'])?'_HTTP_'.$result['http']['status']:'')),
        ]);
    }

    JsonResponse::send($result);
} catch (Throwable $e) {
    if($requestId!=='' && $jobBound){
        try{
            $jobs->patch($requestId,[
                'phase'=>'health-attention',
                'verificationSource'=>$healthSource,
                'error'=>$e->getMessage(),
                'health'=>[
                    'ok'=>false,
                    'checkedAt'=>date(DATE_ATOM),
                    'http'=>null,
                ],
            ]);
        }catch(Throwable){}
    }
    JsonResponse::send(['error'=>$e->getMessage()],400);
}
