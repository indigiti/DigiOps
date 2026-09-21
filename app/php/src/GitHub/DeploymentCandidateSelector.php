<?php
declare(strict_types=1);

namespace DigiOps\GitHub;

final class DeploymentCandidateSelector
{
    public static function exactArtifact(array $artifacts, string $wanted, ?int $artifactId = null): ?array
    {
        $wanted=trim($wanted) ?: 'digiops-release';
        foreach ($artifacts as $artifact) {
            if (!is_array($artifact)) continue;
            if (($artifact['expired']??false) === true) continue;
            if ((string)($artifact['name']??'') !== $wanted) continue;
            if ($artifactId !== null && (int)($artifact['id']??0) !== $artifactId) continue;
            return $artifact;
        }
        return null;
    }

    public static function find(array $workflowRuns, callable $artifactLoader, string $wanted): array
    {
        $wanted=trim($wanted) ?: 'digiops-release';
        $successful=0;
        $latestSuccessful=null;

        foreach ($workflowRuns as $run) {
            if (!is_array($run)) continue;
            if (($run['status']??'') !== 'completed' || ($run['conclusion']??'') !== 'success') continue;
            $successful++;
            if ($latestSuccessful===null) $latestSuccessful=$run;

            $runId=(int)($run['id']??0);
            if ($runId<=0) continue;
            $payload=$artifactLoader($runId);
            $artifacts=is_array($payload) ? ($payload['artifacts']??$payload) : [];
            if (!is_array($artifacts)) $artifacts=[];
            $artifact=self::exactArtifact($artifacts,$wanted);
            if ($artifact!==null) {
                return [
                    'run'=>$run,
                    'artifact'=>$artifact,
                    'artifacts'=>$artifacts,
                    'match'=>'configured-name',
                    'successfulRunsChecked'=>$successful,
                    'latestSuccessfulRun'=>$latestSuccessful,
                ];
            }
        }

        return [
            'run'=>null,
            'artifact'=>null,
            'artifacts'=>[],
            'match'=>'none',
            'successfulRunsChecked'=>$successful,
            'latestSuccessfulRun'=>$latestSuccessful,
        ];
    }
}
