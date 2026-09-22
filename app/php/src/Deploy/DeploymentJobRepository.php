<?php
declare(strict_types=1);

namespace DigiOps\Deploy;

use DigiOps\Security\PathGuard;
use DigiOps\Support\Files;
use RuntimeException;

final class DeploymentJobRepository
{
    private string $dir;

    private const PHASE_LABELS = [
        'created'=>'Created',
        'candidate-validation'=>'Candidate validation',
        'preflight'=>'Preflight',
        'downloading-artifact'=>'Artifact download',
        'artifact-verified'=>'Artifact integrity',
        'remote-upload'=>'Target upload',
        'validating'=>'Payload validation',
        'snapshotting'=>'Pre-deploy snapshot',
        'staging-release'=>'Release staging',
        'publishing'=>'Publication',
        'switching'=>'Atomic switch',
        'authoritative-confirmation'=>'Authoritative confirmation',
        'complete'=>'Authoritative confirmation',
        'health-verified'=>'Health verification',
        'health-attention'=>'Health verification',
        'failed'=>'Deployment failure',
    ];

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?: DIGIOPS_PRIVATE_ROOT . '/jobs/deployments';
    }

    public function create(array $job): array
    {
        $requestId = $this->requestId((string)($job['requestId'] ?? ''));
        $project = PathGuard::slug((string)($job['project'] ?? ''));
        $now = date(DATE_ATOM);
        $record = array_merge([
            'requestId'=>$requestId,
            'project'=>$project,
            'projectName'=>(string)($job['projectName'] ?? $project),
            'targetId'=>(string)($job['targetId'] ?? 'local'),
            'commit'=>strtolower(trim((string)($job['commit'] ?? ''))),
            'runId'=>(int)($job['runId'] ?? 0),
            'runNumber'=>(int)($job['runNumber'] ?? 0),
            'artifactId'=>(int)($job['artifactId'] ?? 0),
            'artifactDigest'=>(string)($job['artifactDigest'] ?? ''),
            'requestedBy'=>(string)($job['requestedBy'] ?? 'system'),
            'state'=>'queued',
            'phase'=>'created',
            'progress'=>0,
            'release'=>'',
            'error'=>'',
            'health'=>null,
            'verificationSource'=>'',
            'verification'=>[],
            'createdAt'=>$now,
            'startedAt'=>$now,
            'updatedAt'=>$now,
            'completedAt'=>null,
        ], $job);
        $record['requestId']=$requestId;
        $record['project']=$project;
        $record['updatedAt']=$now;
        $record['verification']=$this->updateVerification([], $record, $record, $now);
        $file=$this->file($requestId);
        return Files::mutateJson($file, [], static function(array $existing) use ($record,$project): array {
            if($existing){
                if(($existing['project']??'')!==$project) throw new RuntimeException('DEPLOYMENT_REQUEST_CONFLICT');
                return $existing;
            }
            return $record;
        });
    }

    public function patch(string $requestId, array $patch): array
    {
        $requestId=$this->requestId($requestId);
        $file=$this->file($requestId);
        return Files::mutateJson($file, [], function(array $current) use ($requestId,$patch): array {
            if (!$current) throw new RuntimeException('DEPLOYMENT_JOB_NOT_FOUND');
            $now=date(DATE_ATOM);
            $next=array_merge($current,$patch);
            $next['requestId']=$requestId;
            $next['progress']=max(0,min(100,(int)($next['progress']??0)));
            $next['updatedAt']=$now;
            if (in_array((string)($next['state']??''),['deployed','failed','cancelled','attention'],true) && empty($next['completedAt'])) {
                $next['completedAt']=$now;
            }
            $next['verification']=$this->updateVerification($current,$next,$patch,$now);
            return $next;
        });
    }

    public function get(string $requestId): ?array
    {
        $requestId=$this->requestId($requestId);
        $row=Files::readJson($this->file($requestId), []);
        return $row ?: null;
    }

    public function active(?string $project = null): array
    {
        $project=$project!==null ? PathGuard::slug($project) : null;
        return array_values(array_filter($this->all(100), static function(array $row) use ($project): bool {
            if ($project!==null && ($row['project']??'')!==$project) return false;
            if(!in_array((string)($row['state']??''),['queued','running','pending','unavailable','verifying'],true)) return false;
            $updated=strtotime((string)($row['updatedAt']??''));
            return is_int($updated) && $updated >= time()-1800;
        }));
    }

    public function all(int $limit = 100): array
    {
        if (!is_dir($this->dir)) return [];
        $rows=[];
        foreach (array_diff(scandir($this->dir) ?: [], ['.','..']) as $name) {
            if (!preg_match('/^[a-f0-9]{32}\.json$/',$name)) continue;
            $row=Files::readJson($this->dir.'/'.$name,[]);
            if ($row) $rows[]=$row;
        }
        usort($rows, static fn(array $a,array $b): int => strcmp((string)($b['updatedAt']??''),(string)($a['updatedAt']??'')));
        return array_slice($rows,0,max(1,min(500,$limit)));
    }

    public function latestForProject(string $project): ?array
    {
        $project=PathGuard::slug($project);
        foreach ($this->all(200) as $row) if (($row['project']??'')===$project) return $row;
        return null;
    }

    private function updateVerification(array $current, array $next, array $patch, string $now): array
    {
        $history=is_array($current['verification']??null) ? array_values(array_filter($current['verification'],'is_array')) : [];
        $phase=(string)($next['phase']??'created');
        if($phase==='')$phase='created';
        $state=(string)($next['state']??'queued');
        $source=(string)($patch['verificationSource']??($next['verificationSource']??''));
        $error=array_key_exists('error',$patch) ? (string)$patch['error'] : '';
        $status=$this->verificationStatus($state,$phase,$error);

        $lastIndex=count($history)-1;
        $last=$lastIndex>=0 ? $history[$lastIndex] : null;
        $lastPhase=is_array($last) ? (string)($last['phase']??'') : '';

        if($lastPhase!=='' && $lastPhase!==$phase && in_array((string)($last['status']??''),['running','waiting','unavailable'],true)){
            $history[$lastIndex]['status']='passed';
            $history[$lastIndex]['updatedAt']=$now;
            $history[$lastIndex]['completedAt']=$now;
            if((string)($history[$lastIndex]['error']??'')!=='')$history[$lastIndex]['error']='';
        }

        if($lastPhase===$phase){
            $history[$lastIndex]['status']=$status;
            $history[$lastIndex]['updatedAt']=$now;
            if($source!=='')$history[$lastIndex]['source']=$source;
            if($error!=='')$history[$lastIndex]['error']=$error;
            elseif(in_array($status,['passed','running','waiting'],true))$history[$lastIndex]['error']='';
            if(in_array($status,['passed','failed','attention'],true))$history[$lastIndex]['completedAt']=$now;
            return array_slice($history,-40);
        }

        $history[]=[
            'phase'=>$phase,
            'label'=>self::PHASE_LABELS[$phase]??ucwords(str_replace(['-','_'],' ',$phase)),
            'status'=>$status,
            'progress'=>(int)($next['progress']??0),
            'source'=>$source,
            'error'=>$error,
            'startedAt'=>$now,
            'updatedAt'=>$now,
            'completedAt'=>in_array($status,['passed','failed','attention'],true)?$now:null,
        ];
        return array_slice($history,-40);
    }

    private function verificationStatus(string $state, string $phase, string $error): string
    {
        if($state==='failed')return 'failed';
        if($phase==='health-attention' || $state==='attention')return 'attention';
        if($state==='unavailable')return 'unavailable';
        if(in_array($state,['queued','pending'],true))return 'waiting';
        if($state==='deployed' || $phase==='health-verified' || $phase==='complete')return 'passed';
        if($error!=='')return 'attention';
        return 'running';
    }

    private function file(string $requestId): string
    {
        Files::ensureDir($this->dir,0700);
        return $this->dir.'/'.$requestId.'.json';
    }

    private function requestId(string $value): string
    {
        $value=strtolower(trim($value));
        if (!preg_match('/^[a-f0-9]{32}$/',$value)) throw new RuntimeException('REQUEST_ID_INVALID');
        return $value;
    }
}
