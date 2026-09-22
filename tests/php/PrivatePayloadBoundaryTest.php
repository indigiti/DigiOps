<?php
declare(strict_types=1);

$root=sys_get_temp_dir().'/digiops-private-boundary-test-'.bin2hex(random_bytes(4));
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

use DigiOps\Deploy\ReleaseManager;
use DigiOps\Support\Files;

$payload=$root.'/payload';
Files::ensureDir($payload.'/app');
Files::ensureDir($payload.'/agent');
Files::ensureDir($payload.'/build');

$manager=new ReleaseManager();
$method=new ReflectionMethod(ReleaseManager::class,'validatePrivatePayload');
$method->setAccessible(true);
$method->invoke($manager,'digiops',$payload);

Files::ensureDir($payload.'/vault');
try{
    $method->invoke($manager,'digiops',$payload);
    throw new RuntimeException('DIGIOPS_PRIVATE_STATE_OVERLAY_ALLOWED');
}catch(ReflectionException $e){
    throw $e;
}catch(Throwable $e){
    $actual=$e->getPrevious() ?: $e;
    if($actual->getMessage()!=='DIGIOPS_PRIVATE_PAYLOAD_UNMANAGED_vault') throw $e;
}

$method->invoke($manager,'other-app',$payload);

Files::removeTree($root);
echo "PrivatePayloadBoundaryTest PASS\n";
