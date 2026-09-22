<?php
declare(strict_types=1);

define('DIGIOPS_SOURCE_ROOT',dirname(__DIR__,2));

spl_autoload_register(static function(string $class): void {
    $prefix='DigiOps\\';
    if(!str_starts_with($class,$prefix))return;
    $relative=str_replace('\\','/',substr($class,strlen($prefix)));
    $path=dirname(__DIR__,2).'/app/php/src/'.$relative.'.php';
    if(is_file($path))require_once $path;
});

use DigiOps\GitHub\GitHubClient;

$client=new GitHubClient('test-token');
$method=new ReflectionMethod(GitHubClient::class,'isRetryableArtifactError');
$method->setAccessible(true);

$retryable=[
    'ARTIFACT_BLOB_FAILED_200_CURL_28_EXPECTED_43844439_RECEIVED_33062912:Operation timed out after 180000 milliseconds',
    'ARTIFACT_BLOB_FAILED_0_CURL_7_EXPECTED_-1_RECEIVED_0:Failed to connect',
    'ARTIFACT_API_FAILED_502_CURL_0_EXPECTED_0_RECEIVED_0',
    'ARTIFACT_BLOB_INCOMPLETE_EXPECTED_43844439_RECEIVED_43840000',
];

foreach($retryable as $message){
    if($method->invoke($client,$message)!==true){
        throw new RuntimeException('RETRYABLE_ARTIFACT_ERROR_NOT_RETRIED:'.$message);
    }
}

$terminal=[
    'ARTIFACT_DIGEST_MISMATCH',
    'ARTIFACT_NOT_ZIP',
    'ARTIFACT_REDIRECT_INVALID',
    'ARTIFACT_BLOB_FAILED_404_CURL_0_EXPECTED_0_RECEIVED_0',
];

foreach($terminal as $message){
    if($method->invoke($client,$message)!==false){
        throw new RuntimeException('TERMINAL_ARTIFACT_ERROR_RETRIED:'.$message);
    }
}

$ref=new ReflectionClass(GitHubClient::class);
$timeout=$ref->getReflectionConstant('ARTIFACT_TRANSFER_TIMEOUT');
$attempts=$ref->getReflectionConstant('ARTIFACT_ATTEMPTS');
if(!$timeout || $timeout->getValue()!==600) throw new RuntimeException('ARTIFACT_TIMEOUT_NOT_600');
if(!$attempts || $attempts->getValue()!==3) throw new RuntimeException('ARTIFACT_ATTEMPTS_NOT_3');

echo "GitHubClientRetryTest PASS\n";
