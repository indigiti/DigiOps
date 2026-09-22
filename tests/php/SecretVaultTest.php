<?php
declare(strict_types=1);

$root=sys_get_temp_dir().'/digiops-vault-test-'.bin2hex(random_bytes(4));
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

use DigiOps\Security\SecretVault;
use DigiOps\Support\Files;

$file=$root.'/private/vault/secrets.json';
$vault=new SecretVault($file);
$vault->put('alpha','one');
(new SecretVault($file))->put('beta','two');

if($vault->get('alpha')!=='one' || $vault->get('beta')!=='two') {
    throw new RuntimeException('VAULT_SEQUENTIAL_MUTATION_FAILED');
}

if(function_exists('pcntl_fork')){
    $children=[];
    for($i=0;$i<8;$i++){
        $pid=pcntl_fork();
        if($pid===-1) throw new RuntimeException('FORK_FAILED');
        if($pid===0){
            try{
                (new SecretVault($file))->put('parallel.'.$i,'value-'.$i);
                exit(0);
            }catch(Throwable){
                exit(1);
            }
        }
        $children[]=$pid;
    }
    foreach($children as $pid){
        pcntl_waitpid($pid,$status);
        if(!pcntl_wifexited($status) || pcntl_wexitstatus($status)!==0) throw new RuntimeException('VAULT_PARALLEL_CHILD_FAILED');
    }
    $vault=new SecretVault($file);
    for($i=0;$i<8;$i++){
        if($vault->get('parallel.'.$i)!=='value-'.$i) throw new RuntimeException('VAULT_LOST_UPDATE');
    }
}

$vault->delete('alpha');
if($vault->get('alpha')!==null || $vault->get('beta')!=='two') throw new RuntimeException('VAULT_DELETE_FAILED');

file_put_contents($file,'{broken');
try{
    (new SecretVault($file))->put('gamma','three');
    throw new RuntimeException('CORRUPT_VAULT_OVERWRITTEN');
}catch(RuntimeException $e){
    if($e->getMessage()!=='JSON_STATE_INVALID') throw $e;
}
if(file_get_contents($file)!=='{broken') throw new RuntimeException('CORRUPT_VAULT_MUTATED');

Files::removeTree($root);
echo "SecretVaultTest PASS\n";
