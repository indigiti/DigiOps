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
$transports=[
    [[true,true],'shell'],
    [[true,false],'shell'],
    [[false,true],'watchdog'],
    [[false,false],''],
];
foreach($transports as [$args,$expected]){
    $actual=RuntimeActivator::selectTransport($args[0],$args[1]);
    if($actual!==$expected)throw new RuntimeException('TRANSPORT_SELECTION_FAILED_'.json_encode([$args,$actual,$expected]));
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

// Build the exact staged/target shape used by QSYN watchdog activation.
$staged=$root.'/staged';
$target=$root.'/target';
@mkdir($staged.'/scripts',0700,true);
@mkdir($staged.'/go-engine',0700,true);
@mkdir($staged.'/config',0700,true);
@mkdir($target.'/go-engine/run',0700,true);
file_put_contents($staged.'/scripts/digiops-runtime-activate.py',"# fixture\n");
file_put_contents($staged.'/go-engine/watchdog.sh',"#!/bin/bash\n");
$port=18997;
file_put_contents($staged.'/config/modules.json',json_encode([
    'modules'=>['api-engine'=>['address'=>'127.0.0.1:'.$port]],
],JSON_PRETTY_PRINT));
file_put_contents($target.'/go-engine/run/watchdog-state.json',json_encode([
    'last_run_unix'=>time(),
    'status'=>'HEALTHY',
]));

$watchdogMethod=new ReflectionMethod(RuntimeActivator::class,'watchdogCapability');
$watchdogMethod->setAccessible(true);
$watchdog=$watchdogMethod->invoke(null,$staged,$target);
if(empty($watchdog['available']))throw new RuntimeException('WATCHDOG_CAPABILITY_NOT_DETECTED_'.($watchdog['reason']??'UNKNOWN'));

// Mock the two loopback certification endpoints. The watchdog transport itself
// performs no shell execution; proc_open here only starts the test HTTP fixture.
$router=$root.'/router.php';
file_put_contents($router,<<<PHP
<?php
header('Content-Type: application/json');
if(str_contains((string)(\$_SERVER['REQUEST_URI']??''),'/v1/diagnostics/pipeline')){
    echo json_encode([
        'ok'=>true,
        'profile'=>'live',
        'running_commit'=>'{$commit}',
        'release_id'=>'test-release',
        'config_fingerprint'=>'test-config',
        'commit_consistent'=>true,
        'release_consistent'=>true,
        'config_consistent'=>true,
    ]);
    return;
}
if(str_contains((string)(\$_SERVER['REQUEST_URI']??''),'/v1/platform')){
    echo json_encode([
        'ok'=>true,
        'stack_profile'=>'live',
        'provider_authority'=>['ok'=>true,'violations'=>[]],
        'data_authority_violations'=>[],
    ]);
    return;
}
http_response_code(404);
echo json_encode(['ok'=>false]);
PHP
);
$server=proc_open(
    [PHP_BINARY,'-S','127.0.0.1:'.$port,$router],
    [0=>['pipe','r'],1=>['file',$root.'/server.out','a'],2=>['file',$root.'/server.err','a']],
    $serverPipes,
    $root
);
if(!is_resource($server))throw new RuntimeException('TEST_HTTP_SERVER_START_FAILED');
fclose($serverPipes[0]);
usleep(300000);
try{
    @mkdir($target.'/scripts',0700,true);
    @mkdir($target.'/config',0700,true);
    file_put_contents($target.'/scripts/digiops-runtime-activate.py',"# fixture\n");
    file_put_contents($target.'/config/modules.json',(string)file_get_contents($staged.'/config/modules.json'));
    $watchResult=RuntimeActivator::run(
        $target.'/scripts/digiops-runtime-activate.py',
        $commit,
        $target,
        ['transport'=>'watchdog']+$watchdog
    );
    if(($watchResult['executor']??'')!=='watchdog_request')throw new RuntimeException('WATCHDOG_EXECUTOR_NOT_USED');
    if(($watchResult['running_commit']??'')!==$commit)throw new RuntimeException('WATCHDOG_COMMIT_NOT_CERTIFIED');
    $request=$target.'/go-engine/run/module-restart-request.json';
    if(!is_file($request))throw new RuntimeException('WATCHDOG_RESTART_REQUEST_NOT_WRITTEN');
    $requestData=json_decode((string)file_get_contents($request),true);
    if(($requestData['expected_commit']??'')!==$commit)throw new RuntimeException('WATCHDOG_REQUEST_COMMIT_MISMATCH');
}finally{
    proc_terminate($server,15);
    proc_close($server);
}

file_put_contents($target.'/go-engine/run/watchdog-state.json',json_encode(['last_run_unix'=>time()-181]));
$stale=$watchdogMethod->invoke(null,$staged,$target);
if(($stale['available']??true)!==false || ($stale['reason']??'')!=='RUNTIME_ACTIVATION_WATCHDOG_NOT_RECENT'){
    throw new RuntimeException('STALE_WATCHDOG_NOT_REJECTED');
}

$releaseManager=(string)file_get_contents(dirname(__DIR__,2).'/app/php/src/Deploy/ReleaseManager.php');
if(str_contains($releaseManager,'proc_open('))throw new RuntimeException('RELEASE_MANAGER_DIRECT_PROC_OPEN_DEPENDENCY');
if(!str_contains($releaseManager,'runtimeActivationPlan'))throw new RuntimeException('RELEASE_MANAGER_WATCHDOG_PREFLIGHT_MISSING');

function rmTree(string $path): void {
    if(!file_exists($path)&&!is_link($path))return;
    if(is_file($path)||is_link($path)){@unlink($path);return;}
    foreach(array_diff(scandir($path)?:[],['.','..']) as $name)rmTree($path.'/'.$name);
    @rmdir($path);
}
rmTree($root);
echo "RuntimeActivatorTest PASS\n";
