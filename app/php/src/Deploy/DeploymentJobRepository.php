<?php
declare(strict_types=1);

namespace DigiOps\Deploy;

use DigiOps\Security\PathGuard;
use DigiOps\Support\Files;
use RuntimeException;

final class DeploymentJobRepository
{
    private string $dir;

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
            'createdAt'=>$now,
            'startedAt'=>$now,
            'updatedAt'=>$now,
            'completedAt'=>null,
        ], $job);
        $record['requestId']=$requestId;
        $record['project']=$project;
        $record['updatedAt']=$now;
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
            $next=array_merge($current,$patch);
            $next['requestId']=$requestId;
            $next['progress']=max(0,min(100,(int)($next['progress']??0)));
            $next['updatedAt']=date(DATE_ATOM);
            if (in_array((string)($next['state']??''),['deployed','failed','cancelled','attention'],true) && empty($next['completedAt'])) {
                $next['completedAt']=date(DATE_ATOM);
            }
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
