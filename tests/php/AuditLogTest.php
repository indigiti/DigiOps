<?php
declare(strict_types=1);

$root=sys_get_temp_dir().'/digiops-audit-test-'.bin2hex(random_bytes(4));
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

use DigiOps\Audit\AuditLog;
use DigiOps\Support\Files;

$file=$root.'/audit.ndjson';
$log=new AuditLog($file);
$log->write('ONE',['v'=>1],['username'=>'tester']);
$log->write('TWO',['v'=>2],['username'=>'tester']);
$rows=$log->recent(10);
assert(count($rows)===2);
assert(($rows[0]['event']??'')==='TWO');
assert(($rows[1]['event']??'')==='ONE');
assert(($rows[0]['previousHash']??'')===($rows[1]['hash']??''));
assert(trim((string)file_get_contents($file.'.head'))===($rows[0]['hash']??''));

Files::removeTree($root);
echo "AuditLogTest PASS\n";
