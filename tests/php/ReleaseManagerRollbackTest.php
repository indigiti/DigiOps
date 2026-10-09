<?php
declare(strict_types=1);

// Full ReleaseManager rollback regression: the old executable remains live
// while private bytes are published by an atomic rename. A failed public
// switch must restore the private bytes and leave the registry unchanged.
$root=sys_get_temp_dir().'/digiops-release-rollback-'.bin2hex(random_bytes(5));
define('DIGIOPS_PRIVATE_ROOT',$root.'/digiops-private');
define('DIGIOPS_APP_HOME',$root);
define('DIGIOPS_SOURCE_ROOT',dirname(__DIR__,2));

spl_autoload_register(static function(string $class): void {
    $prefix='DigiOps\\';
    if(!str_starts_with($class,$prefix)) return;
    $relative=str_replace('\\','/',substr($class,strlen($prefix)));
    $path=dirname(__DIR__,2).'/app/php/src/'.$relative.'.php';
    if(is_file($path)) require_once $path;
});

use DigiOps\Registry\ProjectRegistry;
use DigiOps\Deploy\ReleaseManager;
use DigiOps\Support\Files;

$proc=null;
try {
    $registry=new ProjectRegistry();
    $registry->upsert(['id'=>'qsyn','repo'=>'indigiti/qsyn','branch'=>'main']);
    $public=$root.'/public_html/qsyn';
    $private=$root.'/private_html/qsyn';
    $releaseRoot=DIGIOPS_PRIVATE_ROOT.'/projects/qsyn/releases';
    Files::ensureDir($public);
    Files::ensureDir($private.'/app/bin');
    Files::ensureDir($private.'/runtime');
    file_put_contents($private.'/runtime/admin-auth.json','unchanged-private-credentials');
    file_put_contents($public.'/index.php','<?php echo "current";');

    $liveBin=$private.'/app/bin/qsyn-stream';
    if(!copy(PHP_BINARY,$liveBin)) throw new RuntimeException('TEST_LIVE_BINARY_SETUP_FAILED');
    chmod($liveBin,0750);
    $beforeInode=fileinode($liveBin);
    $beforeHash=hash_file('sha256',$liveBin);
    $proc=proc_open(
        [$liveBin,'-r','usleep(15000000);'],
        [0=>['pipe','r'],1=>['file',$root.'/run.out','a'],2=>['file',$root.'/run.err','a']],
        $pipes,
        $root
    );
    if(!is_resource($proc)) throw new RuntimeException('TEST_SERVICE_START_FAILED');
    fclose($pipes[0]);
    usleep(200000);
    if(!(proc_get_status($proc)['running']??false)) {
        throw new RuntimeException('TEST_SERVICE_NOT_RUNNING');
    }

    $release=$releaseRoot.'/release-54';
    Files::ensureDir($release.'/public');
    Files::ensureDir($release.'/private/app/bin');
    file_put_contents($release.'/public/index.php','<?php echo "rollback";');
    if(!copy('/bin/true',$release.'/private/app/bin/qsyn-stream')) {
        throw new RuntimeException('TEST_RELEASE_BINARY_SETUP_FAILED');
    }
    chmod($release.'/private/app/bin/qsyn-stream',0750);
    Files::writeJson($release.'/meta.json',[
        'commit'=>str_repeat('a',40),
        'id'=>'release-54',
    ]);

    $manager=new ReleaseManager($registry);
    $result=$manager->rollback('qsyn','release-54',['username'=>'test']);
    if(($result['ok']??false)!==true) throw new RuntimeException('ROLLBACK_NOT_SUCCESSFUL');
    clearstatcache(true,$liveBin);
    if(fileinode($liveBin)===$beforeInode) throw new RuntimeException('LIVE_BINARY_OVERWRITTEN_IN_PLACE');
    if(hash_file('sha256',$liveBin)!==hash_file('sha256',$release.'/private/app/bin/qsyn-stream')) {
        throw new RuntimeException('ROLLBACK_PRIVATE_BINARY_WRONG');
    }
    if((fileperms($liveBin)&0777)!==0750) throw new RuntimeException('ROLLBACK_BINARY_MODE_WRONG');
    if(hash_file('sha256',$liveBin)===$beforeHash) throw new RuntimeException('ROLLBACK_BINARY_NOT_CHANGED');
    if(!(proc_get_status($proc)['running']??false)) throw new RuntimeException('ROLLBACK_KILLED_OLD_INODE');
    if(file_get_contents($private.'/runtime/admin-auth.json')!=='unchanged-private-credentials') {
        throw new RuntimeException('ROLLBACK_PRIVATE_RUNTIME_STATE_OVERWRITTEN');
    }
    if(file_get_contents($public.'/index.php')!=='<?php echo "rollback";') {
        throw new RuntimeException('ROLLBACK_PUBLIC_NOT_SWITCHED');
    }
    if(($registry->find('qsyn')['commit']??'')!==str_repeat('a',40)) {
        throw new RuntimeException('ROLLBACK_REGISTRY_NOT_UPDATED');
    }

    // Force an invalid public target to make the switch fail AFTER the
    // private overlay. The original private target must be restored.
    $failed=$releaseRoot.'/release-fail';
    Files::ensureDir($failed.'/public');
    Files::ensureDir($failed.'/private/app/bin');
    file_put_contents($failed.'/public/index.php','<?php echo "should-fail";');
    if(!copy('/bin/cat',$failed.'/private/app/bin/qsyn-stream')) {
        throw new RuntimeException('TEST_FAILURE_BINARY_SETUP_FAILED');
    }
    chmod($failed.'/private/app/bin/qsyn-stream',0750);
    Files::writeJson($failed.'/meta.json',['commit'=>str_repeat('b',40)]);

    Files::removeTree($public);
    file_put_contents($public,'block-directory-rename');
    $expectedHash=hash_file('sha256',$liveBin);
    try {
        $manager->rollback('qsyn','release-fail',['username'=>'test']);
        throw new RuntimeException('EXPECTED_PUBLICATION_FAILURE_NOT_THROWN');
    } catch(RuntimeException $e) {
        if($e->getMessage()==='EXPECTED_PUBLICATION_FAILURE_NOT_THROWN') throw $e;
    }
    if(hash_file('sha256',$liveBin)!==$expectedHash) {
        throw new RuntimeException('ROLLBACK_PUBLIC_FAILURE_DID_NOT_RESTORE_PRIVATE');
    }
    if(file_get_contents($private.'/runtime/admin-auth.json')!=='unchanged-private-credentials') {
        throw new RuntimeException('ROLLBACK_FAILURE_CHANGED_RUNTIME_STATE');
    }
    if(($registry->find('qsyn')['commit']??'')!==str_repeat('a',40)) {
        throw new RuntimeException('FAILED_ROLLBACK_UPDATED_REGISTRY');
    }
    foreach(scandir(dirname($public))?:[] as $entry){
        if(str_starts_with($entry,'.qsyn.rollback-')) throw new RuntimeException('ROLLBACK_TEMP_LEAKED');
    }

    echo "ReleaseManagerRollbackTest PASS\n";
} finally {
    if(is_resource($proc)){
        $status=proc_get_status($proc);
        if(($status['running']??false)===true) proc_terminate($proc,15);
        proc_close($proc);
    }
    if(is_dir($root)) Files::removeTree($root);
}
