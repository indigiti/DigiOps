<?php
declare(strict_types=1);

namespace DigiOps\Targets;

use DigiOps\Registry\ProjectRegistry;
use RuntimeException;

final class TargetService
{
    public function __construct(
        private TargetRegistry $targets = new TargetRegistry(),
        private ProjectRegistry $projects = new ProjectRegistry(),
        private RemoteAgentClient $agents = new RemoteAgentClient()
    ) {}

    public function forProject(string $projectId): array
    {
        $project=$this->projects->find($projectId);
        if(!$project) throw new RuntimeException('PROJECT_NOT_FOUND');
        $targetId=(string)($project['targetId']??'local');
        $target=$this->targets->find($targetId);
        if(!$target) throw new RuntimeException('TARGET_NOT_FOUND');
        return $target;
    }

    public function test(string $targetId): array
    {
        $target=$this->targets->find($targetId);
        if(!$target) throw new RuntimeException('TARGET_NOT_FOUND');
        if($target['id']==='local'){
            return [
                'ok'=>true,
                'target'=>'local',
                'latencyMs'=>0,
                'agentVersion'=>'embedded',
                'capabilities'=>$target['capabilities'],
                'runtime'=>['php'=>PHP_VERSION,'host'=>php_uname('n')],
            ];
        }
        $result=$this->agents->test($target);
        $this->targets->patchRuntime($targetId,[
            'status'=>'connected',
            'capabilities'=>(array)($result['capabilities']??[]),
            'lastVerified'=>date(DATE_ATOM),
            'latencyMs'=>$result['_latencyMs']??null,
            'agentVersion'=>(string)($result['agentVersion']??''),
        ]);
        return $result;
    }

    public function remoteRequest(string $projectId,string $action,array $payload=[]): array
    {
        $target=$this->forProject($projectId);
        if(($target['id']??'local')==='local') throw new RuntimeException('LOCAL_TARGET_USE_LOCAL_DRIVER');
        return $this->agents->request($target,$action,$payload);
    }
}
