<?php
declare(strict_types=1);
require_once __DIR__.'/_bootstrap.php';

use DigiOps\Audit\AuditLog;
use DigiOps\KrushiWorld\SetupManager;
use DigiOps\Registry\ProjectRegistry;
use DigiOps\Security\Session;
use DigiOps\Support\JsonResponse;

$user=Session::requireRole(['admin']);
$project=(new ProjectRegistry())->find('krushiworld');
if (!$project || strtolower((string)$project['repo'])!=='indigiti/krushiworld'
    || ($project['targetId']??'local')!=='local'
    || trim((string)$project['publicPath'],'/')!=='public_html/krushiworld'
    || trim((string)$project['privatePath'],'/')!=='private_html/krushiworld') {
    JsonResponse::send(['error'=>'LOCAL_KRUSHIWORLD_PROJECT_REQUIRED'],403);
}
$storage=DIGIOPS_APP_HOME.'/private_html/krushiworld/storage';
$manager=new SetupManager($storage);
if (($_SERVER['REQUEST_METHOD']??'GET')==='GET') {
    try { JsonResponse::send(['ok'=>true,'status'=>$manager->status()]); }
    catch(\Throwable){ JsonResponse::send(['error'=>'PRIVATE_CONFIGURATION_UNAVAILABLE'],500); }
}
if (($_SERVER['REQUEST_METHOD']??'')!=='POST') JsonResponse::send(['error'=>'METHOD_NOT_ALLOWED'],405);
Session::assertCsrf();
$site=(string)($_SERVER['HTTP_SEC_FETCH_SITE']??'');
if ($site!=='' && !in_array($site,['same-origin','none'],true)) JsonResponse::send(['error'=>'CROSS_ORIGIN_DENIED'],403);
$origin=(string)($_SERVER['HTTP_ORIGIN']??'');
if ($origin!=='' && !hash_equals('https://stage.digiti.in',strtolower(rtrim($origin,'/'))))
    JsonResponse::send(['error'=>'INVALID_ORIGIN'],403);
$raw=file_get_contents('php://input');
if (!is_string($raw)||strlen($raw)>4096)JsonResponse::send(['error'=>'INVALID_REQUEST_SIZE'],400);
$data=json_decode($raw,true);
if (!is_array($data))JsonResponse::send(['error'=>'INVALID_JSON'],400);
$action=(string)($data['action']??'');
try {
    if($action==='configure') {
        $key=$data['api_key']??'';
        if(!is_string($key))JsonResponse::send(['error'=>'INVALID_KEY'],400);
        $status=$manager->configure(trim($key));
        (new AuditLog())->write('KRUSHIWORLD_SETUP_CONFIG_UPDATED',['project'=>'krushiworld','keyConfigured'=>$status['keyConfigured']],$user);
        JsonResponse::send(['ok'=>true,'status'=>$status]);
    }
    if($action==='grant') {
        $grant=$manager->issueGrant();
        (new AuditLog())->write('KRUSHIWORLD_ADMIN_BOOTSTRAP_GRANT',['project'=>'krushiworld','expiresInSeconds'=>900],$user);
        JsonResponse::send(['ok'=>true,'grant'=>$grant,'expiresInSeconds'=>900]);
    }
    JsonResponse::send(['error'=>'INVALID_ACTION'],400);
} catch(\Throwable $e) {
    $safe=['INVALID_AGMARKNET_KEY_FORMAT','CONFIGURE_STAGING_FIRST','PRODUCTION_MODE_LOCKED','INSECURE_FILE_PERMISSIONS',
    'UNSAFE_STORAGE','STORAGE_UNAVAILABLE','PRIVATE_WRITE_FAILED','PRIVATE_LOCK_FAILED','INVALID_SETTINGS'];
    $error=in_array($e->getMessage(),$safe,true)?$e->getMessage():'PRIVATE_CONFIGURATION_UNAVAILABLE';
    JsonResponse::send(['error'=>$error],400);
}
