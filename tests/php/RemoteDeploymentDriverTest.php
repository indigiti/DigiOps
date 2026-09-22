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
use DigiOps\Targets\TargetService;

final class FakeTargetService extends TargetService
{
    public function __construct(private string $failAction='', private string $error='') {}

    public function remoteRequest(string $projectId,string $action,array $payload=[]): array
    {
        if($action===$this->failAction) throw new RuntimeException($this->error);
        if($action==='deploy-start') return ['uploadId'=>str_repeat('a',24),'chunkBytes'=>524288];
        if($action==='deploy-chunk'){
            $chunk=base64_decode((string)($payload['data']??''),true);
            return ['received'=>(int)($payload['offset']??0)+strlen($chunk===false?'':$chunk)];
        }
        if($action==='deploy-commit') return ['release'=>'r1','commit'=>str_repeat('b',40)];
        throw new RuntimeException('UNEXPECTED_ACTION_'.$action);
    }
}

$zip=$root.'/artifact.zip';
@mkdir($root,0750,true);
file_put_contents($zip,'test-payload');
$project=['publicPath'=>'public_html/app/','privatePath'=>'private_html/app/'];
$meta=['commit'=>str_repeat('b',40),'requestId'=>str_repeat('c',32)];

$driver=new RemoteDeploymentDriver(new FakeTargetService('deploy-commit','TARGET_CONNECT_FAILED_28:timeout'));
try{
    $driver->deploy('app',$zip,$project,$meta);
    throw new RuntimeException('UNCERTAIN_COMMIT_NOT_WRAPPED');
}catch(RuntimeException $e){
    if(!str_starts_with($e->getMessage(),'REMOTE_COMMIT_UNCERTAIN_TARGET_CONNECT_FAILED_28')) throw $e;
}

$driver=new RemoteDeploymentDriver(new FakeTargetService('deploy-start','TARGET_CONNECT_FAILED_28:timeout'));
try{
    $driver->deploy('app',$zip,$project,$meta);
    throw new RuntimeException('PRECOMMIT_FAILURE_NOT_THROWN');
}catch(RuntimeException $e){
    if(str_starts_with($e->getMessage(),'REMOTE_COMMIT_UNCERTAIN_')) throw new RuntimeException('PRECOMMIT_FAILURE_MARKED_UNCERTAIN');
    if(!str_starts_with($e->getMessage(),'TARGET_CONNECT_FAILED_28')) throw $e;
}

@unlink($zip);
@rmdir($root);
echo "RemoteDeploymentDriverTest PASS\n";
