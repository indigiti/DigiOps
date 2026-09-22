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
]);
assert(($created['requestId']??'')===$id);
assert(count($repo->active())===1);
assert(($repo->latestForProject('app-1')['requestId']??'')===$id);

$repo->patch($id,['phase'=>'publishing','progress'=>82]);
$current=$repo->get($id);
assert(($current['phase']??'')==='publishing');
assert((int)($current['progress']??0)===82);

$repo->patch($id,['state'=>'deployed','phase'=>'complete','progress'=>100,'release'=>'r1']);
assert(count($repo->active())===0);
assert(($repo->get($id)['release']??'')==='r1');

Files::removeTree($root);
echo "DeploymentJobRepositoryTest PASS\n";
