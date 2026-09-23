<?php
declare(strict_types=1);

$root=sys_get_temp_dir().'/digiops-remote-driver-test-'.bin2hex(random_bytes(4));
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

use DigiOps\Targets\RemoteDeploymentDriver;

$method=new ReflectionMethod(RemoteDeploymentDriver::class,'isUncertainCommitError');
$method->setAccessible(true);

$uncertain=[
    'TARGET_CONNECT_FAILED_28:timeout',
    'TARGET_INVALID_RESPONSE_HTTP_502_BYTES_120',
    'TARGET_HTTP_503',
];
foreach($uncertain as $message){
    if($method->invoke(null,$message)!==true) throw new RuntimeException('UNCERTAIN_COMMIT_NOT_CLASSIFIED_'.$message);
}

$terminal=[
    'GITHUB_ACTIONS_HTTP_502',
    'GITHUB_ARTIFACTS_HTTP_504',
    'PREFLIGHT_DISK_SPACE_LOW',
    'TARGET_AGENT_UPGRADE_REQUIRED_atomic-switch-v1',
    'ARTIFACT_DIGEST_MISMATCH',
];
foreach($terminal as $message){
    if($method->invoke(null,$message)!==false) throw new RuntimeException('PRECOMMIT_FAILURE_MARKED_UNCERTAIN_'.$message);
}


$activationMethod=new ReflectionMethod(RemoteDeploymentDriver::class,'artifactRequiresRuntimeActivation');
$activationMethod->setAccessible(true);

$zipWithHook=$root.'/with-hook.zip';
$zip=new ZipArchive();
if($zip->open($zipWithHook,ZipArchive::CREATE)!==true) throw new RuntimeException('TEST_ZIP_CREATE_FAILED');
$zip->addFromString('RELEASE.json',json_encode([
    'runtimeActivation'=>['activationHook'=>'scripts/digiops-runtime-activate.py'],
],JSON_UNESCAPED_SLASHES));
$zip->close();
if($activationMethod->invoke(null,$zipWithHook)!==true) throw new RuntimeException('RUNTIME_ACTIVATION_MANIFEST_NOT_DETECTED');

$zipWithoutHook=$root.'/without-hook.zip';
$zip=new ZipArchive();
if($zip->open($zipWithoutHook,ZipArchive::CREATE)!==true) throw new RuntimeException('TEST_ZIP_CREATE_FAILED');
$zip->addFromString('RELEASE.json',json_encode(['application'=>'STATIC'],JSON_UNESCAPED_SLASHES));
$zip->close();
if($activationMethod->invoke(null,$zipWithoutHook)!==false) throw new RuntimeException('STATIC_RELEASE_FALSE_POSITIVE');

echo "RemoteDeploymentDriverTest PASS\n";
