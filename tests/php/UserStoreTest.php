<?php
declare(strict_types=1);

$root=sys_get_temp_dir().'/digiops-user-test-'.bin2hex(random_bytes(4));
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

use DigiOps\Security\UserStore;
use DigiOps\Support\Files;

$file=$root.'/users/users.json';
$store=new UserStore($file);
$admin=$store->createInitialAdmin('admin-one','Admin One','correct-horse-battery');
if(($admin['role']??'')!=='admin') throw new RuntimeException('INITIAL_ADMIN_ROLE_FAILED');

try{
    $store->createInitialAdmin('admin-two','Admin Two','correct-horse-staple');
    throw new RuntimeException('SECOND_INITIAL_ADMIN_ALLOWED');
}catch(RuntimeException $e){
    if($e->getMessage()!=='ALREADY_INSTALLED') throw $e;
}

file_put_contents($file,'{not-json');
try{
    $store->hasUsers();
    throw new RuntimeException('CORRUPT_USER_STORE_ACCEPTED');
}catch(RuntimeException $e){
    if($e->getMessage()!=='USER_STORE_INVALID') throw $e;
}

Files::removeTree($root);
echo "UserStoreTest PASS\n";
