<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use DigiOps\Deploy\ReleaseManager;
use DigiOps\GitHub\GitHubClient;
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
        if ($runId<=0) {
            $runs=$client->workflowRuns($project['repo'],$project['branch'],20)['workflow_runs']??[];
            $match=null;
            foreach ($runs as $run) if (($run['status']??'')==='completed' && ($run['conclusion']??'')==='success') { $match=$run; break; }
            if (!$match) throw new RuntimeException('NO_SUCCESSFUL_WORKFLOW_RUN');
            $runId=(int)$match['id'];
            $commit=(string)($match['head_sha']??$commit);
        }
        $artifacts=$client->artifacts($project['repo'],$runId)['artifacts']??[];
        $wanted=$project['artifactName'] ?: 'digiops-release';
        $match=null;
        foreach ($artifacts as $artifact) if (($artifact['name']??'')===$wanted && !($artifact['expired']??false)) { $match=$artifact; break; }
        if (!$match && count($artifacts)===1) $match=$artifacts[0];
        if (!$match) throw new RuntimeException('DEPLOY_ARTIFACT_NOT_FOUND');
        $artifactId=(int)$match['id'];
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
        }
    } finally { @unlink($zip); }
    JsonResponse::send($result);
} catch (Throwable $e) {
    JsonResponse::send(['error'=>$e->getMessage()],400);
}
