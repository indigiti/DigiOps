<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use DigiOps\Security\Session;
use DigiOps\Security\UserStore;
use DigiOps\Support\JsonResponse;

$user=Session::user();
$store=new UserStore();
$build=[];
$buildFile=DIGIOPS_PRIVATE_ROOT . '/build/release.json';
if(is_file($buildFile)){$decoded=json_decode((string)file_get_contents($buildFile),true);if(is_array($decoded))$build=$decoded;}
JsonResponse::send([
    'ok'=>true,'service'=>'DigiOps','version'=>(string)($build['version']??'unknown'),
    'installed'=>$store->hasUsers(),'authenticated'=>$user!==null,
    'php'=>PHP_VERSION,
    'extensions'=>['curl'=>extension_loaded('curl'),'zip'=>extension_loaded('zip'),'openssl'=>extension_loaded('openssl'),'sodium'=>extension_loaded('sodium')],
    'privateConfigured'=>is_dir(DIGIOPS_PRIVATE_ROOT),
    'time'=>date(DATE_ATOM),
]);
