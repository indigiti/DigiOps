<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use DigiOps\Deploy\ReleaseManager;
use DigiOps\Audit\AuditLog;
use DigiOps\GitHub\GitHubClient;
use DigiOps\GitHub\DeploymentCandidateResolver;
use DigiOps\Registry\ProjectRegistry;
use DigiOps\Security\SecretVault;
use DigiOps\Security\Session;
use DigiOps\Support\Files;
use DigiOps\Support\JsonResponse;
use DigiOps\Targets\TargetService;
use DigiOps\Targets\RemoteDeploymentDriver;

if ($_SERVER['REQUEST_METHOD']!=='POST') JsonResponse::send(['error'=>'METHOD_NOT_ALLOWED'],405);
$user=Session::requireRole(['admin','operator']);
Session::assertCsrf();
$data=json_decode(file_get_contents('php://input') ?: '',true);
if (!is_array($data)) JsonResponse::send(['error'=>'INVALID_JSON'],400);

try {
    $projectId=(string)($data['project']??'');
    $project=(new ProjectRegistry())->find($projectId);
    if (!$project) throw new RuntimeException('PROJECT_NOT_FOUND');
    $token=(new SecretVault())->get('github.token');
    if (!$token) throw new RuntimeException('GITHUB_NOT_CONNECTED');
    $client=new GitHubClient($token);

    $runId=(int)($data['runId']??0);
    $artifactId=(int)($data['artifactId']??0);
    $commit=(string)($data['commit']??'');
    if ($artifactId<=0) {
        if ($runId>0) {
            $artifacts=$client->artifacts($project['repo'],$runId)['artifacts']??[];
            $wanted=$project['artifactName'] ?: 'digiops-release';
            $match=null;
            foreach ($artifacts as $artifact) {
                if (($artifact['name']??'')===$wanted && !($artifact['expired']??false)) { $match=$artifact; break; }
            }
            if (!$match) {
                $active=array_values(array_filter($artifacts,fn($a)=>is_array($a)&&!($a['expired']??false)));
                if (count($active)===1) $match=$active[0];
            }
            if (!$match) throw new RuntimeException('DEPLOY_ARTIFACT_NOT_FOUND');
            $artifactId=(int)$match['id'];
        } else {
            $runs=$client->workflowRuns($project['repo'],$project['branch'],20)['workflow_runs']??[];
            $resolved=DeploymentCandidateResolver::resolve(
                $runs,
                (string)($project['artifactName'] ?: 'digiops-release'),
                fn(int $id): array => $client->artifacts($project['repo'],$id),
                10
            );
            $match=$resolved['artifact']??null;
            $matchedRun=$resolved['run']??null;
            if (!$matchedRun) throw new RuntimeException('NO_SUCCESSFUL_WORKFLOW_RUN');
            if (!$match) throw new RuntimeException('DEPLOY_ARTIFACT_NOT_FOUND');
            $runId=(int)($matchedRun['id']??0);
            $artifactId=(int)($match['id']??0);
            $commit=(string)($matchedRun['head_sha']??$commit);
        }
    }

    Files::ensureDir(DIGIOPS_PRIVATE_ROOT . '/tmp');
    $zip=DIGIOPS_PRIVATE_ROOT . '/tmp/artifact-' . bin2hex(random_bytes(6)) . '.zip';
    $client->downloadArtifact($project['repo'],$artifactId,$zip);
    try {
        $target=(new TargetService())->forProject($projectId);
        if (($target['id']??'local')==='local') {
            $result=(new ReleaseManager())->deployArtifact($projectId,$zip,['commit'=>$commit,'artifactId'=>$artifactId],$user);
        } else {
            $result=(new RemoteDeploymentDriver())->deploy($projectId,$zip,$project,['commit'=>$commit,'artifactId'=>$artifactId]);
            (new ProjectRegistry())->patchRuntime($projectId,[
                'status'=>'deployed',
                'health'=>'pending',
                'commit'=>$commit?:'—',
                'release'=>(string)($result['release']??'Remote release'),
                'lastDeploy'=>date(DATE_ATOM),
                'update'=>false,
            ]);
            (new AuditLog())->write('REMOTE_DEPLOY_SUCCESS',[
                'project'=>$projectId,
                'target'=>$target['id']??'',
                'release'=>$result['release']??null,
                'commit'=>$commit,
                'artifactId'=>$artifactId,
            ],$user);
        }
    } finally { @unlink($zip); }
    JsonResponse::send($result);
} catch (Throwable $e) {
    JsonResponse::send(['error'=>$e->getMessage()],400);
}
