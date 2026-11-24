<?php
declare(strict_types=1);

namespace DigiOps\Targets;

use RuntimeException;

final class RemoteDeploymentDriver
{
    public function __construct(private TargetService $targets = new TargetService()) {}

    public function deploy(string $projectId,string $zip,array $project,array $meta): array
    {
        if(!is_file($zip)) throw new RuntimeException('ARTIFACT_MISSING');
        $size=filesize($zip);
        if($size===false) throw new RuntimeException('ARTIFACT_SIZE_FAILED');
        $sha=hash_file('sha256',$zip);
        $requiresRuntimeActivation=self::artifactRequiresRuntimeActivation($zip);
        $fullSize=$size;
        $transfer=$zip;
        $patch=null;
        $delta=null;
        $eligible=strtolower($projectId)==='qnext' && $size<=67108864 && hash_equals((string)($meta['downloadSha256']??''),$sha);
        try {
            // Read-only negotiation; old agents and unavailable bases use full ZIP.
            if($eligible){
                try{
                    $ping=$this->targets->remoteRequest($projectId,'ping',[]);
                    if(in_array('delta-transfer-v1',(array)($ping['capabilities']??[]),true)){
                        if(!class_exists('DigiOpsArtifactDelta',false)){
                            if(!defined('DIGIOPS_DELTA_LIBRARY_ONLY'))define('DIGIOPS_DELTA_LIBRARY_ONLY',true);
                            require_once DIGIOPS_SOURCE_ROOT.'/agent/digiops-agent.php';
                        }
                        $base=$this->targets->remoteRequest($projectId,'deploy-delta-base',['project'=>$projectId]);
                        $base=$base['base']??[];
                        if(is_array($base)&&$base){
                            $patch=tempnam(sys_get_temp_dir(),'digiops-delta-');
                            if($patch!==false && \DigiOpsArtifactDelta::create($zip,$base,$patch)){
                                $patchSize=filesize($patch);
                                if($patchSize!==false && $patchSize>0 && $patchSize<$fullSize){
                                    $delta=['baseSha'=>$base['sha256'],'targetSha'=>$sha,'targetSize'=>$fullSize];
                                    $transfer=$patch;$size=$patchSize;
                                }
                            }
                        }
                    }
                }catch(\Throwable){/* fallback is safe only before deploy-start */}
            }

        $start=$this->targets->remoteRequest($projectId,'deploy-start',[
            'project'=>$projectId,
            'size'=>$size,
            'sha256'=>hash_file('sha256',$transfer),
            'meta'=>[
                'commit'=>(string)($meta['commit']??''),
                'artifactId'=>(string)($meta['artifactId']??''),
                'artifactDigest'=>(string)($meta['artifactDigest']??''),
                'downloadSha256'=>(string)($meta['downloadSha256']??''),
                'requestId'=>(string)($meta['requestId']??''),
                'deltaEligible'=>$eligible,
                ...($delta===null?[]:['delta'=>$delta]),
            ],
        ]);
        $uploadId=(string)($start['uploadId']??'');
        $chunkBytes=max(65536,min(524288,(int)($start['chunkBytes']??524288)));
        if($uploadId==='') throw new RuntimeException('REMOTE_UPLOAD_START_FAILED');

        $fp=fopen($transfer,'rb');
        if(!$fp) throw new RuntimeException('ARTIFACT_READ_FAILED');
        $offset=0;
        try{
            while(!feof($fp)){
                $chunk=fread($fp,$chunkBytes);
                if($chunk===false) throw new RuntimeException('ARTIFACT_READ_FAILED');
                if($chunk==='') break;
                $result=$this->targets->remoteRequest($projectId,'deploy-chunk',[
                    'project'=>$projectId,
                    'uploadId'=>$uploadId,
                    'offset'=>$offset,
                    'data'=>base64_encode($chunk),
                ]);
                $received=(int)($result['received']??-1);
                if($received!==$offset+strlen($chunk))throw new RuntimeException('REMOTE_UPLOAD_OFFSET_MISMATCH');
                $offset=$received;
            }
        } finally {
            fclose($fp);
        }

        try {
            $result=$this->targets->remoteRequest($projectId,'deploy-commit',[
                'project'=>$projectId,
                'uploadId'=>$uploadId,
                'publicPath'=>$project['publicPath'],
                'privatePath'=>$project['privatePath'],
            ]);
            if($requiresRuntimeActivation){
                $activation=is_array($result['activation']??null)?$result['activation']:[];
                if(($activation['required']??false)!==true || strtoupper(trim((string)($activation['status']??'')))!=='SUCCESS'){
                    throw new RuntimeException('REMOTE_RUNTIME_ACTIVATION_NOT_CERTIFIED');
                }
                $expectedCommit=strtolower(trim((string)($meta['commit']??'')));
                $runningCommit=strtolower(trim((string)($activation['running_commit']??'')));
                if($expectedCommit!=='' && (!preg_match('/^[a-f0-9]{40}$/',$runningCommit) || !hash_equals($expectedCommit,$runningCommit))){
                    throw new RuntimeException('REMOTE_RUNNING_COMMIT_MISMATCH');
                }
            }
            $result['transfer']=$result['transfer']??['mode'=>$delta===null?'full':'delta','bytes'=>$size,'fullBytes'=>$fullSize];
            return $result;
        } catch (RuntimeException $e) {
            $message=$e->getMessage();
            if (self::isUncertainCommitError($message)) {
                throw new RuntimeException('REMOTE_COMMIT_UNCERTAIN_'.$message, 0, $e);
            }
            throw $e;
        }
        } finally {
            if(is_string($patch))@unlink($patch);
        }
    }

    private static function artifactRequiresRuntimeActivation(string $zip): bool
    {
        if(!class_exists('ZipArchive')) throw new RuntimeException('ZIP_EXTENSION_UNAVAILABLE');
        $archive=new \ZipArchive();
        if($archive->open($zip)!==true) throw new RuntimeException('INVALID_ZIP');
        try{
            $manifestName=null;
            for($i=0;$i<$archive->numFiles;$i++){
                $name=str_replace('\\','/',(string)$archive->getNameIndex($i));
                if($name==='RELEASE.json'){$manifestName=$name;break;}
                if($manifestName===null && str_ends_with($name,'/RELEASE.json'))$manifestName=$name;
            }
            if($manifestName===null)return false;
            $raw=$archive->getFromName($manifestName);
            if(!is_string($raw)||$raw==='')return false;
            $manifest=json_decode($raw,true);
            if(!is_array($manifest))return false;
            $runtime=is_array($manifest['runtimeActivation']??null)?$manifest['runtimeActivation']:[];
            return trim((string)($runtime['activationHook']??''))!=='';
        } finally {
            $archive->close();
        }
    }

    private static function isUncertainCommitError(string $message): bool
    {
        return (bool)preg_match('/^(TARGET_CONNECT_FAILED_|TARGET_INVALID_RESPONSE_HTTP_50[234]|TARGET_HTTP_50[234])/', $message);
    }

    public function rollback(string $projectId,array $project,string $release): array
    {
        return $this->targets->remoteRequest($projectId,'rollback',[
            'project'=>$projectId,
            'release'=>$release,
            'publicPath'=>$project['publicPath'],
            'privatePath'=>$project['privatePath'],
        ]);
    }

    public function releases(string $projectId): array
    {
        $result=$this->targets->remoteRequest($projectId,'releases',['project'=>$projectId]);
        return (array)($result['releases']??[]);
    }

    public function health(string $projectId,array $project): array
    {
        $result=$this->targets->remoteRequest($projectId,'health',[
            'project'=>$projectId,
            'url'=>$project['url'],
            'healthPath'=>$project['healthPath'],
            'publicPath'=>$project['publicPath'],
            'privatePath'=>$project['privatePath'],
        ]);
        unset($result['_latencyMs']);
        $result['ok']=(bool)(($result['http']['ok']??false) && ($result['storage']['exists']??false));
        return $result;
    }

    public function files(string $projectId,array $project,string $scope,string $path): array
    {
        $result=$this->targets->remoteRequest($projectId,'files',[
            'project'=>$projectId,
            'scope'=>$scope,
            'path'=>$path,
            'publicPath'=>$project['publicPath'],
            'privatePath'=>$project['privatePath'],
        ]);
        return (array)($result['listing']??[]);
    }
}
