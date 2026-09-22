<?php
declare(strict_types=1);

define('DIGIOPS_PRIVATE_ROOT', sys_get_temp_dir().'/digiops-switch-test-private');
define('DIGIOPS_APP_HOME', sys_get_temp_dir().'/digiops-switch-test-home');
define('DIGIOPS_SOURCE_ROOT', dirname(__DIR__,2));

spl_autoload_register(static function(string $class): void {
    $prefix='DigiOps\\';
    if(!str_starts_with($class,$prefix))return;
    $relative=str_replace('\\','/',substr($class,strlen($prefix)));
    $path=dirname(__DIR__,2).'/app/php/src/'.$relative.'.php';
    if(is_file($path))require_once $path;
});

use DigiOps\Deploy\AtomicReleaseSwitcher;
use DigiOps\Support\Files;

$root=sys_get_temp_dir().'/digiops-switch-test-'.bin2hex(random_bytes(4));
$target=$root.'/app';
$prepared=$root.'/.app.publish-test';
Files::ensureDir($target);
file_put_contents($target.'/old.txt','old');
Files::ensureDir($prepared);
file_put_contents($prepared.'/new.txt','new');

(new AtomicReleaseSwitcher())->switch($prepared,$target,'app');
assert(is_file($target.'/new.txt'));
assert(!is_file($target.'/old.txt'));
assert(!is_dir($prepared));

$failed=false;
try{(new AtomicReleaseSwitcher())->switch($root.'/missing',$target,'app');}
catch(Throwable $e){$failed=$e->getMessage()==='PREPARED_RELEASE_MISSING';}
assert($failed===true);

Files::removeTree($root);
echo "AtomicReleaseSwitcherTest PASS\n";
