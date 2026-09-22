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

$projectFile=$root.'/private/registry/projects.json';
file_put_contents($projectFile,json_encode([['id'=>'bad project','repo'=>'indigiti/example','branch'=>'main']]));
try{
    (new ProjectRegistry($projectFile))->all();
    throw new RuntimeException('INVALID_PROJECT_RECORD_ACCEPTED');
}catch(RuntimeException $e){
    if($e->getMessage()!=='PROJECT_REGISTRY_INVALID') throw $e;
}

$targetFile=$root.'/private/registry/targets.json';
file_put_contents($targetFile,'{broken');
try{
    (new TargetRegistry($targetFile))->all();
    throw new RuntimeException('CORRUPT_TARGET_REGISTRY_ACCEPTED');
}catch(RuntimeException $e){
    if($e->getMessage()!=='JSON_STATE_INVALID') throw $e;
}

file_put_contents($targetFile,json_encode([['id'=>'remote-1','endpoint'=>'http://insecure.example']]));
try{
    (new TargetRegistry($targetFile))->all();
    throw new RuntimeException('INVALID_TARGET_RECORD_ACCEPTED');
}catch(RuntimeException $e){
    if($e->getMessage()!=='TARGET_REGISTRY_INVALID') throw $e;
}


$healthFile=$root.'/private/registry/health-projects.json';
$healthRegistry=new ProjectRegistry($healthFile);
$healthRegistry->upsert(['id'=>'qsyn','repo'=>'indigiti/syndi','branch'=>'main']);
$healthRegistry->patchRuntime('qsyn',[
    'health'=>'healthy',
    'healthCheckedAt'=>'2026-09-22T20:00:00+05:30',
    'healthDetail'=>[
        'ok'=>true,
        'url'=>'https://stage.digiti.in/qsyn/',
        'checkedAt'=>'2026-09-22T20:00:00+05:30',
        'http'=>['ok'=>true,'status'=>200,'ms'=>41],
        'storage'=>['exists'=>true,'bytes'=>1234,'writable'=>true],
        'runtime'=>['php'=>'8.4.0','curl'=>true,'zip'=>true,'sodium'=>true],
    ],
]);
$healthProject=$healthRegistry->find('qsyn');
if(($healthProject['healthDetail']['url']??'')!=='https://stage.digiti.in/qsyn/') throw new RuntimeException('HEALTH_DETAIL_URL_NOT_PERSISTED');
if((int)($healthProject['healthDetail']['http']['status']??0)!==200) throw new RuntimeException('HEALTH_DETAIL_HTTP_NOT_PERSISTED');
if((int)($healthProject['healthDetail']['storage']['bytes']??0)!==1234) throw new RuntimeException('HEALTH_DETAIL_STORAGE_NOT_PERSISTED');

Files::removeTree($root);
echo "PersistentStateTest PASS\n";
