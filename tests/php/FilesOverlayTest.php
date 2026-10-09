<?php
declare(strict_types=1);

$root=sys_get_temp_dir().'/digiops-overlay-test-'.bin2hex(random_bytes(4));
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

use DigiOps\Support\Files;

$source=$root.'/source';
$target=$root.'/target';
$backup=$root.'/backup';
Files::ensureDir($source.'/app');
Files::ensureDir($target.'/app');
file_put_contents($source.'/app/existing.php','new');
file_put_contents($source.'/app/new.php','new-file');
file_put_contents($source.'/.htaccess','rewrite');
Files::ensureDir($source.'/.runtime-web/php-app/public');
file_put_contents($source.'/.runtime-web/php-app/public/router.php','router');
file_put_contents($target.'/app/existing.php','old');
file_put_contents($target.'/runtime.json','state');

$plan=Files::beginOverlay($source,$target,$backup);
Files::applyOverlay($plan);
if(file_get_contents($target.'/app/existing.php')!=='new') throw new RuntimeException('OVERLAY_UPDATE_FAILED');
if(file_get_contents($target.'/app/new.php')!=='new-file') throw new RuntimeException('OVERLAY_CREATE_FAILED');
if(file_get_contents($target.'/runtime.json')!=='state') throw new RuntimeException('OVERLAY_RUNTIME_STATE_CHANGED');
if(file_get_contents($target.'/.htaccess')!=='rewrite') throw new RuntimeException('OVERLAY_DOTFILE_MISSING');
if(file_get_contents($target.'/.runtime-web/php-app/public/router.php')!=='router') throw new RuntimeException('OVERLAY_DOTDIR_MISSING');

Files::rollbackOverlay($plan);
if(file_get_contents($target.'/app/existing.php')!=='old') throw new RuntimeException('OVERLAY_RESTORE_FAILED');
if(file_exists($target.'/app/new.php')) throw new RuntimeException('OVERLAY_NEW_FILE_NOT_REMOVED');
if(file_get_contents($target.'/runtime.json')!=='state') throw new RuntimeException('OVERLAY_RUNTIME_STATE_LOST');

Files::ensureDir($source.'/conflict');
file_put_contents($target.'/conflict','file');
try{
    Files::beginOverlay($source,$target,$root.'/backup-conflict');
    throw new RuntimeException('OVERLAY_TYPE_CONFLICT_NOT_DETECTED');
}catch(RuntimeException $e){
    if(!str_starts_with($e->getMessage(),'OVERLAY_TYPE_CONFLICT_EXPECTED_DIRECTORY:conflict')) throw $e;
}
@unlink($target.'/conflict');
@rmdir($source.'/conflict');

Files::ensureDir($source.'/go-engine/bin/releases/release-a/modules');
Files::ensureDir($target.'/go-engine/bin/releases/release-a/modules');
$immutableRel='go-engine/bin/releases/release-a/modules/storage-engine';
$immutableSource=$source.'/'.$immutableRel;
$immutableTarget=$target.'/'.$immutableRel;
file_put_contents($immutableSource,'same-binary-bytes');
file_put_contents($immutableTarget,'same-binary-bytes');
@chmod($immutableTarget,0444);

$immutablePlan=Files::beginOverlay($source,$target,$root.'/backup-immutable-identical');
if(!in_array($immutableRel,(array)($immutablePlan['unchangedFiles']??[]),true)){
    throw new RuntimeException('OVERLAY_IDENTICAL_IMMUTABLE_NOT_SKIPPED');
}
Files::applyOverlay($immutablePlan);
if(file_get_contents($immutableTarget)!=='same-binary-bytes'){
    throw new RuntimeException('OVERLAY_IDENTICAL_IMMUTABLE_CHANGED');
}
Files::commitOverlay($immutablePlan);

@chmod($immutableTarget,0644);
file_put_contents($immutableSource,'different-binary-bytes');
try{
    Files::beginOverlay($source,$target,$root.'/backup-immutable-collision');
    throw new RuntimeException('OVERLAY_IMMUTABLE_COLLISION_NOT_DETECTED');
}catch(RuntimeException $e){
    if(!str_starts_with($e->getMessage(),'IMMUTABLE_RELEASE_COLLISION:'.$immutableRel)) throw $e;
}
if(file_get_contents($immutableTarget)!=='same-binary-bytes'){
    throw new RuntimeException('OVERLAY_IMMUTABLE_COLLISION_MODIFIED_TARGET');
}


$rollbackSource=$root.'/rollback-source';
$rollbackTarget=$root.'/rollback-target';
Files::ensureDir($rollbackSource);
Files::ensureDir($rollbackTarget);
file_put_contents($rollbackSource.'/engine','new-engine');
file_put_contents($rollbackTarget.'/engine','old-engine');
$rollbackPlan=Files::beginOverlay($rollbackSource,$rollbackTarget,$root.'/backup-rollback-busy');
Files::applyOverlay($rollbackPlan);
if(file_get_contents($rollbackTarget.'/engine')!=='new-engine') throw new RuntimeException('OVERLAY_ROLLBACK_SETUP_FAILED');
Files::rollbackOverlay($rollbackPlan);
if(file_get_contents($rollbackTarget.'/engine')!=='old-engine') throw new RuntimeException('OVERLAY_ATOMIC_RESTORE_FAILED');

// Regression: Linux holds an executing ELF inode busy while DigiOps replaces
// its path. A same-directory copy + rename must succeed without killing it.
// The downloaded artifact may have lost +x; retain existing executable mode.
if (PHP_OS_FAMILY==='Linux' && function_exists('proc_open') && is_executable('/bin/sleep') && is_file('/bin/true')) {
    $liveRoot=$root.'/live-qsyn';
    $nextRoot=$root.'/next-qsyn';
    $liveBinary=$liveRoot.'/app/bin/qsyn-stream';
    $nextBinary=$nextRoot.'/app/bin/qsyn-stream';
    Files::ensureDir(dirname($liveBinary));
    Files::ensureDir(dirname($nextBinary));
    if (!copy('/bin/sleep',$liveBinary) || !chmod($liveBinary,0755)) {
        throw new RuntimeException('EXECUTABLE_TEST_SETUP_FAILED');
    }
    if (!copy('/bin/true',$nextBinary) || !chmod($nextBinary,0644)) {
        throw new RuntimeException('NEW_BINARY_TEST_SETUP_FAILED');
    }
    $pipes=[];
    $running=proc_open([$liveBinary,'8'],[
        0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']
    ],$pipes);
    if (!is_resource($running)) throw new RuntimeException('EXECUTABLE_START_FAILED');
    try {
        usleep(100000);
        if (!proc_get_status($running)['running']) throw new RuntimeException('EXECUTABLE_STOPPED_EARLY');
        $livePlan=Files::beginOverlay($nextRoot,$liveRoot,$root.'/live-backup');
        Files::applyOverlay($livePlan);
        if (!hash_equals((string)hash_file('sha256',$nextBinary),(string)hash_file('sha256',$liveBinary))) {
            throw new RuntimeException('EXECUTABLE_ATOMIC_REPLACE_FAILED');
        }
        if (!proc_get_status($running)['running']) {
            throw new RuntimeException('EXECUTABLE_PROCESS_INTERRUPTED_BY_REPLACE');
        }
        if (((int)fileperms($liveBinary)&0100)===0) throw new RuntimeException('EXECUTABLE_BIT_LOST_ON_REPLACE');
        if (glob(dirname($liveBinary).'/.qsyn-stream.digiops-*')!==[]) {
            throw new RuntimeException('EXECUTABLE_REPLACE_TMP_LEFT_BEHIND');
        }
        Files::rollbackOverlay($livePlan);
        if (!hash_equals((string)hash_file('sha256','/bin/sleep'),(string)hash_file('sha256',$liveBinary))) {
            throw new RuntimeException('EXECUTABLE_ROLLBACK_BYTES_INCORRECT');
        }
        if (!proc_get_status($running)['running']) {
            throw new RuntimeException('EXECUTABLE_PROCESS_INTERRUPTED_BY_ROLLBACK');
        }
    } finally {
        proc_terminate($running);
        foreach($pipes as $pipe) fclose($pipe);
        proc_close($running);
    }
}

// Destination symlinks must not be followed or replaced by the atomic copier.
$symlinkSource=$root.'/symlink-source';
$symlinkTarget=$root.'/symlink-target';
Files::ensureDir($symlinkSource);
Files::ensureDir($symlinkTarget);
file_put_contents($symlinkSource.'/entry','trusted');
file_put_contents($root.'/outside','outside-safe');
if (symlink($root.'/outside',$symlinkTarget.'/entry')) {
    try {
        Files::copyDir($symlinkSource,$symlinkTarget);
        throw new RuntimeException('COPY_TARGET_SYMLINK_ACCEPTED');
    } catch(RuntimeException $e) {
        if(!str_starts_with($e->getMessage(),'COPY_TARGET_SYMLINK_NOT_ALLOWED:entry')) throw $e;
    }
    if(file_get_contents($root.'/outside')!=='outside-safe') {
        throw new RuntimeException('COPY_TARGET_SYMLINK_CHANGED_OUTSIDE');
    }
}

Files::removeTree($root);
echo "FilesOverlayTest PASS\n";
