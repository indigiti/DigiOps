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

use DigiOps\Health\HealthService;
use DigiOps\Registry\ProjectRegistry;
use DigiOps\Support\Files;

$registry=new ProjectRegistry($root.'/private/registry/projects.json');
$registry->upsert(['id'=>'qsyn','name'=>'QSYN','repo'=>'indigiti/syndi','branch'=>'main','healthPath'=>'/']);
$service=new HealthService($registry);
$method=new ReflectionMethod(HealthService::class,'canonicalOrigin');
$method->setAccessible(true);

putenv('DIGIOPS_CANONICAL_ORIGIN=https://stage.example.test/');
putenv('APP_URL');
if($method->invoke($service)!=='https://stage.example.test') throw new RuntimeException('CANONICAL_ORIGIN_NORMALIZATION_FAILED');
if($service->healthUrl('qsyn')!=='https://stage.example.test/qsyn/') throw new RuntimeException('HEALTH_URL_RESOLUTION_FAILED');

putenv('DIGIOPS_CANONICAL_ORIGIN');
$_SERVER['HTTP_HOST']='169.254.169.254';
try{
    $method->invoke($service);
    throw new RuntimeException('HTTP_HOST_FALLBACK_ALLOWED');
}catch(ReflectionException $e){
    throw $e;
}catch(Throwable $e){
    $actual=$e instanceof ReflectionException ? $e : ($e->getPrevious() ?: $e);
    if($actual->getMessage()!=='HEALTH_ORIGIN_NOT_CONFIGURED') throw $e;
}

putenv('DIGIOPS_CANONICAL_ORIGIN=https://user@example.test');
try{
    $method->invoke($service);
    throw new RuntimeException('CREDENTIALLED_ORIGIN_ALLOWED');
}catch(Throwable $e){
    $actual=$e->getPrevious() ?: $e;
    if($actual->getMessage()!=='HEALTH_ORIGIN_INVALID') throw $e;
}

putenv('DIGIOPS_CANONICAL_ORIGIN');
Files::removeTree($root);
echo "HealthServiceOriginTest PASS\n";
