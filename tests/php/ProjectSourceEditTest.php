<?php
declare(strict_types=1);

$root=sys_get_temp_dir().'/digiops-source-edit-'.bin2hex(random_bytes(4));
define('DIGIOPS_PRIVATE_ROOT',$root.'/private');
define('DIGIOPS_APP_HOME',$root);
define('DIGIOPS_SOURCE_ROOT',dirname(__DIR__,2));
spl_autoload_register(static function(string $class): void {
    $prefix='DigiOps\\';
    if(!str_starts_with($class,$prefix)) return;
    $path=dirname(__DIR__,2).'/app/php/src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
    if(is_file($path))require_once $path;
});
use DigiOps\Registry\ProjectRegistry;
$registry=new ProjectRegistry($root.'/projects.json');
$original=$registry->upsert(['id'=>'qsyn','name'=>'QSYN','repo'=>'indigiti/syndi','branch'=>'main','update'=>true]);
$changed=$registry->updateSource('qsyn','indigiti/qsyn','develop','indigiti/syndi','main');
if($changed['repo']!=='indigiti/qsyn'||$changed['branch']!=='develop'||$changed['update']!==false) throw new RuntimeException('SOURCE_NOT_CHANGED');
if($changed['publicPath']!==$original['publicPath'] || $changed['privatePath']!==$original['privatePath']) throw new RuntimeException('PATH_CHANGED');
try {
    $registry->updateSource('qsyn','indigiti/other','main','indigiti/syndi','main');
    throw new RuntimeException('STALE_UPDATE_ACCEPTED');
} catch(RuntimeException $e) {
    if($e->getMessage()!=='PROJECT_SOURCE_CHANGED_RELOAD') throw $e;
}
try {
    $registry->updateSource('qsyn','https://example.org/evil','main','indigiti/qsyn','develop');
    throw new RuntimeException('BAD_REPOSITORY_ACCEPTED');
} catch(InvalidArgumentException $e) {
    if($e->getMessage()!=='INVALID_REPOSITORY') throw $e;
}
echo "Source edit tests passed\n";
