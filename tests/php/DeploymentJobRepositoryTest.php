<?php
declare(strict_types=1);

$root=sys_get_temp_dir().'/digiops-job-test-'.bin2hex(random_bytes(4));
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

use DigiOps\Deploy\DeploymentJobRepository;
use DigiOps\Support\Files;

$repo=new DeploymentJobRepository($root.'/jobs');
$id=str_repeat('a',32);
$created=$repo->create([
    'requestId'=>$id,
    'project'=>'app-1',
    'projectName'=>'App One',
    'commit'=>str_repeat('b',40),
    'runId'=>123,
    'artifactId'=>456,
    'state'=>'running',
    'phase'=>'preflight',
    'progress'=>12,
    'verificationSource'=>'control-plane-preflight',
]);
assert(($created['requestId']??'')===$id);
assert(count($repo->active())===1);
assert(($repo->latestForProject('app-1')['requestId']??'')===$id);
assert(($created['verification'][0]['phase']??'')==='preflight');
assert(($created['verification'][0]['status']??'')==='running');

$repo->patch($id,['phase'=>'publishing','progress'=>82,'verificationSource'=>'local-release-manager']);
$current=$repo->get($id);
assert(($current['phase']??'')==='publishing');
assert((int)($current['progress']??0)===82);
assert(($current['verification'][0]['status']??'')==='passed');
assert(($current['verification'][1]['phase']??'')==='publishing');
assert(($current['verification'][1]['status']??'')==='running');

$repo->patch($id,['state'=>'deployed','phase'=>'complete','progress'=>100,'release'=>'r1','verificationSource'=>'local-progress']);
$done=$repo->get($id);
assert(count($repo->active())===0);
assert(($done['release']??'')==='r1');
assert(($done['verification'][1]['status']??'')==='passed');
assert(($done['verification'][2]['phase']??'')==='complete');
assert(($done['verification'][2]['status']??'')==='passed');

$failedId=str_repeat('c',32);
$repo->create([
    'requestId'=>$failedId,
    'project'=>'app-2',
    'commit'=>str_repeat('d',40),
    'state'=>'running',
    'phase'=>'artifact-verified',
    'progress'=>30,
]);
$repo->patch($failedId,[
    'state'=>'failed',
    'phase'=>'artifact-verified',
    'progress'=>100,
    'error'=>'ARTIFACT_DIGEST_MISMATCH',
    'verificationSource'=>'deploy-api',
]);
$failed=$repo->get($failedId);
assert(($failed['phase']??'')==='artifact-verified');
assert(($failed['verification'][0]['status']??'')==='failed');
assert(($failed['verification'][0]['error']??'')==='ARTIFACT_DIGEST_MISMATCH');
assert(($failed['verification'][0]['source']??'')==='deploy-api');

Files::removeTree($root);
echo "DeploymentJobRepositoryTest PASS\n";
