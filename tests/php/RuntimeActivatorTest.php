<?php
declare(strict_types=1);

$root=sys_get_temp_dir().'/digiops-runtime-activator-test-'.bin2hex(random_bytes(4));
if(!mkdir($root,0700,true) && !is_dir($root))throw new RuntimeException('TEST_ROOT_CREATE_FAILED');

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

use DigiOps\Deploy\RuntimeActivator;

$cases=[
    [[true,false,false],'proc_open'],
    [[true,true,true],'proc_open'],
    [[false,true,true],'exec'],
    [[false,true,false],''],
    [[false,false,true],''],
];
foreach($cases as [$args,$expected]){
    $actual=RuntimeActivator::selectExecutor($args[0],$args[1],$args[2]);
    if($actual!==$expected)throw new RuntimeException('EXECUTOR_SELECTION_FAILED_'.json_encode([$args,$actual,$expected]));
}

$cap=RuntimeActivator::capability();
if(empty($cap['available']))throw new RuntimeException('CI_RUNTIME_ACTIVATION_UNAVAILABLE_'.($cap['reason']??'UNKNOWN'));
if(!in_array((string)($cap['executor']??''),['proc_open','exec'],true))throw new RuntimeException('CI_RUNTIME_ACTIVATION_EXECUTOR_INVALID');

$hook=$root.'/activate.py';
file_put_contents($hook,<<<'PY'
import json,sys
commit=sys.argv[sys.argv.index("--expected-commit")+1]
print(json.dumps({"ok": True, "running_commit": commit}))
PY
);
$commit=str_repeat('a',40);
$result=RuntimeActivator::run($hook,$commit,$root);
if(($result['required']??false)!==true || ($result['status']??'')!=='SUCCESS')throw new RuntimeException('RUNTIME_ACTIVATION_NOT_CERTIFIED');
if(($result['running_commit']??'')!==$commit)throw new RuntimeException('RUNTIME_ACTIVATION_COMMIT_MISMATCH');
if(!in_array((string)($result['executor']??''),['proc_open','exec'],true))throw new RuntimeException('RUNTIME_ACTIVATION_EXECUTOR_MISSING');

$releaseManager=(string)file_get_contents(dirname(__DIR__,2).'/app/php/src/Deploy/ReleaseManager.php');
if(str_contains($releaseManager,'proc_open('))throw new RuntimeException('RELEASE_MANAGER_DIRECT_PROC_OPEN_DEPENDENCY');

@unlink($hook);
@rmdir($root.'/private');
@rmdir($root);
echo "RuntimeActivatorTest PASS\n";
