<?php
declare(strict_types=1);

namespace DigiOps\GitHub;

final class DeploymentCandidateResolver
{
    /**
     * @param array<int,array<string,mixed>> $workflowRuns
     * @param callable(int):array<string,mixed> $artifactLoader
     * @return array<string,mixed>
     */
    public static function resolve(array $workflowRuns, string $wanted, callable $artifactLoader, int $scanLimit = 10): array
    {
        $wanted = trim($wanted) !== '' ? trim($wanted) : 'digiops-release';
        $successfulSeen = 0;
        $scanned = [];
        $fallback = null;
        $expiredWanted = false;

        foreach ($workflowRuns as $run) {
            if (!is_array($run)) continue;
            if (($run['status'] ?? '') !== 'completed' || ($run['conclusion'] ?? '') !== 'success') continue;
            if ($successfulSeen >= max(1, $scanLimit)) break;
            $successfulSeen++;

            $runId = (int)($run['id'] ?? 0);
            if ($runId <= 0) continue;

            $payload = $artifactLoader($runId);
            $artifacts = array_values(array_filter((array)($payload['artifacts'] ?? []), 'is_array'));
            $active = array_values(array_filter($artifacts, static fn(array $a): bool => !($a['expired'] ?? false)));
            $names = array_values(array_map(static fn(array $a): string => (string)($a['name'] ?? ''), $artifacts));
            $scanned[] = [
                'runId' => $runId,
                'runNumber' => $run['run_number'] ?? null,
                'name' => (string)($run['name'] ?? ''),
                'sha' => (string)($run['head_sha'] ?? ''),
                'artifactNames' => $names,
                'activeArtifacts' => count($active),
            ];

            foreach ($artifacts as $artifact) {
                if (($artifact['name'] ?? '') !== $wanted) continue;
                if ($artifact['expired'] ?? false) {
                    $expiredWanted = true;
                    continue;
                }
                return [
                    'run' => $run,
                    'artifact' => $artifact,
                    'artifacts' => $artifacts,
                    'match' => 'configured-name',
                    'reason' => 'ready',
                    'scanned' => $scanned,
                ];
            }

            if ($fallback === null && count($active) === 1) {
                $fallback = [
                    'run' => $run,
                    'artifact' => $active[0],
                    'artifacts' => $artifacts,
                    'match' => 'single-artifact-fallback',
                    'reason' => 'ready',
                    'scanned' => $scanned,
                ];
            }
        }

        if ($fallback !== null) {
            $fallback['scanned'] = $scanned;
            return $fallback;
        }

        return [
            'run' => null,
            'artifact' => null,
            'artifacts' => [],
            'match' => 'none',
            'reason' => $successfulSeen === 0 ? 'no-successful-workflow-run' : ($expiredWanted ? 'artifact-expired' : 'artifact-not-found'),
            'scanned' => $scanned,
        ];
    }
}
