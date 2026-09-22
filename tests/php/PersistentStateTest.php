<?php
declare(strict_types=1);

$root=sys_get_temp_dir().'/digiops-state-test-'.bin2hex(random_bytes(4));
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

use DigiOps\Registry\ProjectRegistry;
use DigiOps\Targets\TargetRegistry;
use DigiOps\Support\Files;

$projectFile=$root.'/private/registry/projects.json';
Files::ensureDir(dirname($projectFile));
file_put_contents($projectFile,'{broken');
try{
    (new ProjectRegistry($projectFile))->all();
    throw new RuntimeException('CORRUPT_PROJECT_REGISTRY_ACCEPTED');
}catch(RuntimeException $e){
    if($e->getMessage()!=='JSON_STATE_INVALID') throw $e;
}

$targetFile=$root.'/private/registry/targets.json';
file_put_contents($targetFile,'{broken');
try{
    (new TargetRegistry($targetFile))->all();
    throw new RuntimeException('CORRUPT_TARGET_REGISTRY_ACCEPTED');
}catch(RuntimeException $e){
    if($e->getMessage()!=='JSON_STATE_INVALID') throw $e;
}

Files::removeTree($root);
echo "PersistentStateTest PASS\n";
