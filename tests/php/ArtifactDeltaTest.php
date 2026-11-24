<?php
declare(strict_types=1);
define('DIGIOPS_DELTA_LIBRARY_ONLY',true);
require dirname(__DIR__,2).'/agent/digiops-agent.php';

function expectDelta(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function expectDeltaError(callable $fn,string $message):void{
    try{$fn();}catch(Throwable){return;}
    throw new RuntimeException($message);
}
$root=sys_get_temp_dir().'/digiops-delta-test-'.bin2hex(random_bytes(5));
mkdir($root,0700,true);
try{
    // Deterministic, content-anchored blocks with different ZIP entry contents.
    $data='';
    for($i=0;$i<40;$i++){
        $block='';
        for($j=0;$j<700;$j++)$block.=hash('sha256',"$i:$j",true);
        $data.=$block."\xA5\x5A";
    }
    $base=$root.'/base.zip';$target=$root.'/target.zip';$patch=$root.'/patch';$output=$root.'/output.zip';
    // Use actual ZIP containers with uncompressed entries to make the
    // unchanged archive regions reproducible independent of zlib version.
    foreach([$base=>$data,$target=>substr($data,0,200000).'new entry bytes'.substr($data,200090)] as $file=>$content){
        $z=new ZipArchive();expectDelta($z->open($file,ZipArchive::CREATE|ZipArchive::OVERWRITE)===true,'ZIP_CREATE');
        $z->addFromString('runtime.dat',$content);
        $z->setCompressionName('runtime.dat',ZipArchive::CM_STORE);
        $z->close();
    }
    $manifest=DigiOpsArtifactDelta::manifest($base);
    expectDelta(DigiOpsArtifactDelta::create($target,$manifest,$patch),'PATCH_NOT_CREATED');
    expectDelta(filesize($patch)<filesize($target)*0.8,'PATCH_NOT_SMALLER');
    $sha=hash_file('sha256',$target);$size=filesize($target);
    DigiOpsArtifactDelta::reconstruct($base,$patch,$output,$manifest['sha256'],$sha,$size);
    expectDelta(hash_file('sha256',$output)===$sha,'RECONSTRUCTION_NOT_IDENTICAL');
    $wrong=$root.'/wrong.zip';file_put_contents($wrong,'wrong');
    expectDeltaError(fn()=>DigiOpsArtifactDelta::reconstruct($wrong,$patch,$output,$manifest['sha256'],$sha,$size),'WRONG_BASE_ACCEPTED');
    $bad=$root.'/bad.patch';$bytes=file_get_contents($patch);
    file_put_contents($bad,substr($bytes,0,-1));
    @unlink($output);
    expectDeltaError(fn()=>DigiOpsArtifactDelta::reconstruct($base,$bad,$output,$manifest['sha256'],$sha,$size),'TRUNCATED_PATCH_ACCEPTED');
    expectDelta(!file_exists($output),'PARTIAL_OUTPUT_RETAINED');
    $bytes[strlen($bytes)-1]=chr(ord($bytes[strlen($bytes)-1])^1);
    file_put_contents($bad,$bytes);
    expectDeltaError(fn()=>DigiOpsArtifactDelta::reconstruct($base,$bad,$output,$manifest['sha256'],$sha,$size),'CORRUPT_PATCH_ACCEPTED');
    expectDelta(!file_exists($output),'CORRUPT_OUTPUT_RETAINED');
    expectDelta(!DigiOpsArtifactDelta::create($target,['sha256'=>str_repeat('0',64),'chunks'=>[]],$root.'/not-useful'),'USELESS_DELTA_ACCEPTED');
    echo 'ArtifactDeltaTest PASS (full '.filesize($target).' bytes, delta '.filesize($patch)." bytes)\n";
}finally{foreach(glob($root.'/*')?:[] as $f)@unlink($f);@rmdir($root);}
