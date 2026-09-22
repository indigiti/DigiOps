<?php
declare(strict_types=1);

$root=sys_get_temp_dir().'/digiops-browser-test-'.bin2hex(random_bytes(4));
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

use DigiOps\Files\ManagedFileBrowser;
use DigiOps\Registry\ProjectRegistry;
use DigiOps\Support\Files;

$registry=new ProjectRegistry($root.'/private/registry/projects.json');
$registry->upsert(['id'=>'app-1','name'=>'App','repo'=>'indigiti/example','branch'=>'main']);

$appRoot=$root.'/public_html/app-1';
$outside=$root.'/public_html/app-10';
Files::ensureDir($appRoot);
Files::ensureDir($outside);
file_put_contents($outside.'/secret.txt','not-visible');
if(function_exists('symlink') && @symlink($outside,$appRoot.'/escape-link')){
    try{
        (new ManagedFileBrowser($registry))->list('app-1','public','escape-link');
        throw new RuntimeException('SYMLINK_PATH_ESCAPE_ALLOWED');
    }catch(RuntimeException $e){
        if($e->getMessage()!=='PATH_ESCAPE') throw $e;
    }
}

Files::removeTree($root);
echo "ManagedFileBrowserTest PASS\n";
