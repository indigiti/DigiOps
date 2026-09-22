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

        $start=$this->targets->remoteRequest($projectId,'deploy-start',[
            'project'=>$projectId,
            'size'=>$size,
            'sha256'=>$sha,
            'meta'=>[
                'commit'=>(string)($meta['commit']??''),
                'artifactId'=>(string)($meta['artifactId']??''),
                'requestId'=>(string)($meta['requestId']??''),
            ],
        ]);
        $uploadId=(string)($start['uploadId']??'');
        $chunkBytes=max(65536,min(524288,(int)($start['chunkBytes']??524288)));
        if($uploadId==='') throw new RuntimeException('REMOTE_UPLOAD_START_FAILED');

        $fp=fopen($zip,'rb');
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
                $offset=(int)($result['received']??($offset+strlen($chunk)));
            }
        } finally {
            fclose($fp);
        }

        return $this->targets->remoteRequest($projectId,'deploy-commit',[
            'project'=>$projectId,
            'uploadId'=>$uploadId,
            'publicPath'=>$project['publicPath'],
            'privatePath'=>$project['privatePath'],
        ]);
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
