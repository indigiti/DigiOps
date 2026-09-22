<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

@set_time_limit(300);
@ignore_user_abort(true);
header('Cache-Control: no-store, private');
header('X-Accel-Buffering: no');

use DigiOps\Deploy\ReleaseManager;
use DigiOps\Audit\AuditLog;
use DigiOps\GitHub\GitHubClient;
use DigiOps\GitHub\DeploymentCandidateSelector;
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
// Release the PHP session lock before long-running deployment work so
// deploy-status and health requests can run concurrently.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
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
    $commit=trim((string)($data['commit']??''));
    $requestId=strtolower(trim((string)($data['requestId']??'')));
    if($requestId==='' || !preg_match('/^[a-f0-9]{32}$/',$requestId)) $requestId=bin2hex(random_bytes(16));
    $wanted=trim((string)($project['artifactName']??'digiops-release')) ?: 'digiops-release';

    if ($runId>0) {
        $run=$client->workflowRun($project['repo'],$runId);
        if (($run['status']??'')!=='completed' || ($run['conclusion']??'')!=='success') {
            throw new RuntimeException('DEPLOY_WORKFLOW_NOT_SUCCESSFUL');
        }
        if ((string)($run['head_branch']??'') !== (string)$project['branch']) {
            throw new RuntimeException('DEPLOY_WORKFLOW_BRANCH_MISMATCH');
        }
        $runCommit=(string)($run['head_sha']??'');
        if ($commit!=='' && $runCommit!=='' && !hash_equals($runCommit,$commit)) {
            throw new RuntimeException('DEPLOY_COMMIT_MISMATCH');
        }
        $commit=$runCommit!==''?$runCommit:$commit;
        $artifacts=$client->artifacts($project['repo'],$runId)['artifacts']??[];
        $artifact=DeploymentCandidateSelector::exactArtifact(
            is_array($artifacts)?$artifacts:[],
            $wanted,
            $artifactId>0?$artifactId:null
        );
        if ($artifact===null) throw new RuntimeException('DEPLOY_ARTIFACT_NAME_MISMATCH');
        $artifactId=(int)($artifact['id']??0);
    } else {
        $runs=$client->workflowRuns($project['repo'],$project['branch'],20)['workflow_runs']??[];
        $selection=DeploymentCandidateSelector::find(
            is_array($runs)?$runs:[],
            fn(int $candidateRunId): array => $client->artifacts($project['repo'],$candidateRunId),
            $wanted
        );
        $run=$selection['run']??null;
        $artifact=$selection['artifact']??null;
        if (!is_array($run) || !is_array($artifact)) throw new RuntimeException('DEPLOY_ARTIFACT_NOT_FOUND');
        $runId=(int)($run['id']??0);
        $artifactId=(int)($artifact['id']??0);
        $commit=(string)($run['head_sha']??$commit);
    }

    if ($runId<=0 || $artifactId<=0 || $commit==='') throw new RuntimeException('DEPLOY_CANDIDATE_INVALID');

    // Fail fast before downloading a large artifact or mutating a target.
    $targetService=new TargetService();
    $target=$targetService->forProject($projectId);
    if (($target['id']??'local')==='local') {
        if (!class_exists('ZipArchive')) throw new RuntimeException('PREFLIGHT_ZIP_EXTENSION_MISSING');
        if (!is_dir(DIGIOPS_PRIVATE_ROOT) || !is_writable(DIGIOPS_PRIVATE_ROOT)) throw new RuntimeException('PREFLIGHT_PRIVATE_STORAGE_NOT_WRITABLE');
        $publicParent=DIGIOPS_APP_HOME . '/' . dirname((string)$project['publicPath']);
        if (is_dir($publicParent) && !is_writable($publicParent)) throw new RuntimeException('PREFLIGHT_PUBLIC_PARENT_NOT_WRITABLE');
    } else {
        $probe=$targetService->test((string)$target['id']);
        $caps=array_values(array_filter((array)($probe['capabilities']??[]),'is_string'));
        foreach(['deploy-chunked','deployment-status','deployment-request-id'] as $requiredCapability){
            if(!in_array($requiredCapability,$caps,true)) throw new RuntimeException('TARGET_AGENT_UPGRADE_REQUIRED_'.$requiredCapability);
        }
    }

    Files::ensureDir(DIGIOPS_PRIVATE_ROOT . '/tmp');
    $zip=DIGIOPS_PRIVATE_ROOT . '/tmp/artifact-' . bin2hex(random_bytes(6)) . '.zip';
    $client->downloadArtifact($project['repo'],$artifactId,$zip);
    $artifactBytes=filesize($zip);
    if($artifactBytes===false || $artifactBytes<1) throw new RuntimeException('ARTIFACT_DOWNLOAD_EMPTY');
    if(($target['id']??'local')==='local'){
        $free=@disk_free_space(dirname(DIGIOPS_PRIVATE_ROOT));
        $minimum=max(64*1024*1024,$artifactBytes*4);
        if(is_float($free) && $free<$minimum) throw new RuntimeException('PREFLIGHT_DISK_SPACE_LOW');
    }
    try {
        if (($target['id']??'local')==='local') {
            $result=(new ReleaseManager())->deployArtifact($projectId,$zip,['commit'=>$commit,'artifactId'=>$artifactId,'requestId'=>$requestId],$user);
        } else {
            $result=(new RemoteDeploymentDriver())->deploy($projectId,$zip,$project,['commit'=>$commit,'artifactId'=>$artifactId,'requestId'=>$requestId]);
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
    JsonResponse::send(['requestId'=>$requestId]+$result);
} catch (Throwable $e) {
    JsonResponse::send(['error'=>$e->getMessage()],400);
}
