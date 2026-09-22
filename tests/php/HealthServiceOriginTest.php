<?php
declare(strict_types=1);

$root=sys_get_temp_dir().'/digiops-health-origin-test-'.bin2hex(random_bytes(4));
define('DIGIOPS_PRIVATE_ROOT',$root.'/private');
define('DIGIOPS_APP_HOME',$root);
define('DIGIOPS_SOURCE_ROOT',dirname(__DIR__,2));

spl_autoload_register(static function(string $class): void {
    $prefix='DigiOps\\';
    if(!str_starts_with($class,$prefix))return;
    $relative=str_replace('\\','/',substr($class,strlen($prefix)));
    $path=dirname(__DIR__,2).'/app/php/src/'.$relative.'.php';
    if(is_file($path))require_once $path;
});

use DigiOps\Health\HealthOrigin;
use DigiOps\Health\HealthService;
use DigiOps\Registry\ProjectRegistry;
use DigiOps\Support\Files;

$registry=new ProjectRegistry($root.'/private/registry/projects.json');
$registry->upsert(['id'=>'qsyn','name'=>'QSYN','repo'=>'indigiti/syndi','branch'=>'main','healthPath'=>'/']);
$service=new HealthService($registry);

putenv('DIGIOPS_CANONICAL_ORIGIN');
putenv('APP_URL');
Files::writeJson($root.'/private/config/infrastructure.json',[
    'applicationOrigin'=>'https://stage.example.test',
]);

if(HealthOrigin::configured()!=='https://stage.example.test') throw new RuntimeException('STORED_HEALTH_ORIGIN_NOT_USED');
if($service->healthUrl('qsyn')!=='https://stage.example.test/qsyn/') throw new RuntimeException('HEALTH_URL_RESOLUTION_FAILED');

putenv('DIGIOPS_CANONICAL_ORIGIN=https://override.example.test/');
if(HealthOrigin::configured()!=='https://override.example.test') throw new RuntimeException('ENV_HEALTH_ORIGIN_OVERRIDE_FAILED');
putenv('DIGIOPS_CANONICAL_ORIGIN');

$_SERVER['HTTP_HOST']='169.254.169.254';
@unlink($root.'/private/config/infrastructure.json');
try{
    HealthOrigin::configured();
    throw new RuntimeException('HTTP_HOST_FALLBACK_ALLOWED');
}catch(RuntimeException $e){
    if($e->getMessage()!=='HEALTH_ORIGIN_NOT_CONFIGURED') throw $e;
}

foreach([
    'http://stage.example.test'=>'HEALTH_ORIGIN_HTTPS_REQUIRED',
    'https://user@example.test'=>'HEALTH_ORIGIN_INVALID',
    'https://stage.example.test/path'=>'HEALTH_ORIGIN_PATH_NOT_ALLOWED',
] as $origin=>$expected){
    try{
        HealthOrigin::normalize($origin);
        throw new RuntimeException('INVALID_HEALTH_ORIGIN_ACCEPTED');
    }catch(RuntimeException $e){
        if($e->getMessage()!==$expected) throw $e;
    }
}

if(HealthOrigin::normalize('http://localhost:5173/')!=='http://localhost:5173') throw new RuntimeException('LOCAL_DEV_ORIGIN_REJECTED');

Files::removeTree($root);
echo "HealthServiceOriginTest PASS\n";
