<?php
declare(strict_types=1);

$root=sys_get_temp_dir().'/digiops-overlay-test-'.bin2hex(random_bytes(4));
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

use DigiOps\Support\Files;

$source=$root.'/source';
$target=$root.'/target';
$backup=$root.'/backup';
Files::ensureDir($source.'/app');
Files::ensureDir($target.'/app');
file_put_contents($source.'/app/existing.php','new');
file_put_contents($source.'/app/new.php','new-file');
file_put_contents($source.'/.htaccess','rewrite');
Files::ensureDir($source.'/.runtime-web/php-app/public');
file_put_contents($source.'/.runtime-web/php-app/public/router.php','router');
file_put_contents($target.'/app/existing.php','old');
file_put_contents($target.'/runtime.json','state');

$plan=Files::beginOverlay($source,$target,$backup);
Files::applyOverlay($plan);
if(file_get_contents($target.'/app/existing.php')!=='new') throw new RuntimeException('OVERLAY_UPDATE_FAILED');
if(file_get_contents($target.'/app/new.php')!=='new-file') throw new RuntimeException('OVERLAY_CREATE_FAILED');
if(file_get_contents($target.'/runtime.json')!=='state') throw new RuntimeException('OVERLAY_RUNTIME_STATE_CHANGED');
if(file_get_contents($target.'/.htaccess')!=='rewrite') throw new RuntimeException('OVERLAY_DOTFILE_MISSING');
if(file_get_contents($target.'/.runtime-web/php-app/public/router.php')!=='router') throw new RuntimeException('OVERLAY_DOTDIR_MISSING');

Files::rollbackOverlay($plan);
if(file_get_contents($target.'/app/existing.php')!=='old') throw new RuntimeException('OVERLAY_RESTORE_FAILED');
if(file_exists($target.'/app/new.php')) throw new RuntimeException('OVERLAY_NEW_FILE_NOT_REMOVED');
if(file_get_contents($target.'/runtime.json')!=='state') throw new RuntimeException('OVERLAY_RUNTIME_STATE_LOST');

Files::ensureDir($source.'/conflict');
file_put_contents($target.'/conflict','file');
try{
    Files::beginOverlay($source,$target,$root.'/backup-conflict');
    throw new RuntimeException('OVERLAY_TYPE_CONFLICT_NOT_DETECTED');
}catch(RuntimeException $e){
    if(!str_starts_with($e->getMessage(),'OVERLAY_TYPE_CONFLICT_EXPECTED_DIRECTORY:conflict')) throw $e;
}

Files::removeTree($root);
echo "FilesOverlayTest PASS\n";
