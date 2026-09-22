<?php
declare(strict_types=1);

namespace DigiOps\Health;

use DigiOps\Registry\ProjectRegistry;
use DigiOps\Security\PathGuard;
use DigiOps\Support\Files;
use RuntimeException;

final class HealthService
{
    public function __construct(private ProjectRegistry $projects = new ProjectRegistry()) {}

    public function healthUrl(string $projectId): string
    {
        $project = $this->projects->find($projectId);
        if (!$project) throw new RuntimeException('PROJECT_NOT_FOUND');
        $slug = PathGuard::slug($projectId);
        $origin=HealthOrigin::configured();
        return $origin . '/' . $slug . '/' . ltrim((string)$project['healthPath'], '/');
    }

    public function probe(string $projectId): array
    {
        $project = $this->projects->find($projectId);
        if (!$project) throw new RuntimeException('PROJECT_NOT_FOUND');
        $slug = PathGuard::slug($projectId);
        $url = $this->healthUrl($projectId);

        $http = ['ok'=>false,'status'=>null,'ms'=>null];
        if (function_exists('curl_init')) {
            $start = microtime(true);
            $ch = curl_init($url);
            curl_setopt_array($ch,[
                CURLOPT_RETURNTRANSFER=>true,
                CURLOPT_NOBODY=>true,
                CURLOPT_FOLLOWLOCATION=>false,
                CURLOPT_MAXREDIRS=>0,
                CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,
                CURLOPT_TIMEOUT=>8,
                CURLOPT_CONNECTTIMEOUT=>4,
            ]);
            curl_exec($ch);
            $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            $http=['ok'=>$status>=200&&$status<400,'status'=>$status,'ms'=>(int)((microtime(true)-$start)*1000)];
        }

        $public = DIGIOPS_APP_HOME . '/' . $project['publicPath'];
        $storage = ['exists'=>is_dir($public),'bytes'=>Files::directorySize($public),'writable'=>is_dir(dirname($public)) && is_writable(dirname($public))];
        $runtime = ['php'=>PHP_VERSION,'curl'=>extension_loaded('curl'),'zip'=>extension_loaded('zip'),'sodium'=>extension_loaded('sodium')];
        $ok = ($storage['exists'] || $project['status'] !== 'deployed') && $runtime['curl'] && $runtime['zip'] && ($project['status'] !== 'deployed' || $http['ok']);
        $checkedAt=date(DATE_ATOM);
        $result=['ok'=>$ok,'url'=>$url,'http'=>$http,'storage'=>$storage,'runtime'=>$runtime,'checkedAt'=>$checkedAt];
        $this->projects->patchRuntime($slug,[
            'health'=>$ok?'healthy':'attention',
            'healthCheckedAt'=>$checkedAt,
            'healthDetail'=>$result,
        ]);
        return $result;
    }
}
