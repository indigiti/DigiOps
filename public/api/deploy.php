<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

@set_time_limit(2100);
@ignore_user_abort(true);
header('Cache-Control: no-store, private');
header('X-Accel-Buffering: no');

use DigiOps\Deploy\DeploymentJobRepository;
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
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$data=json_decode(file_get_contents('php://input') ?: '',true);
if (!is_array($data)) JsonResponse::send(['error'=>'INVALID_JSON'],400);

$jobs=new DeploymentJobRepository();
$jobCreated=false;
$requestId='';
$projectId='';
$target=null;
$artifactDigest='';

try {
    $projectId=(string)($data['project']??'');
    $project=(new ProjectRegistry())->find($projectId);
    if (!$project) throw new RuntimeException('PROJECT_NOT_FOUND');

    $runId=(int)($data['runId']??0);
    $artifactId=(int)($data['artifactId']??0);
    $commit=strtolower(trim((string)($data['commit']??'')));
    $requestId=strtolower(trim((string)($data['requestId']??'')));
    if($requestId==='' || !preg_match('/^[a-f0-9]{32}$/',$requestId)) $requestId=bin2hex(random_bytes(16));

    foreach($jobs->active($projectId) as $activeJob){
        $activeRequestId=(string)($activeJob['requestId']??'');
        if($activeRequestId===$requestId) continue;
        JsonResponse::send([
            'error'=>'DEPLOYMENT_ALREADY_ACTIVE',
            'requestId'=>$activeRequestId,
            'state'=>(string)($activeJob['state']??'running'),
            'phase'=>(string)($activeJob['phase']??'running'),
            'progress'=>(int)($activeJob['progress']??0),
        ],409);
    }

    $jobs->create([
        'requestId'=>$requestId,
        'project'=>$projectId,
        'projectName'=>(string)($project['name']??$projectId),
        'targetId'=>(string)($project['targetId']??'local'),
        'commit'=>$commit,
        'runId'=>$runId,
        'artifactId'=>$artifactId,
        'requestedBy'=>(string)($user['username']??'operator'),
        'state'=>'running',
        'phase'=>'candidate-validation',
        'progress'=>4,
        'verificationSource'=>'github-candidate',
    ]);
    $jobCreated=true;

    $token=(new SecretVault())->get('github.token');
    if (!$token) throw new RuntimeException('GITHUB_NOT_CONNECTED');
    $client=new GitHubClient($token);
    $wanted=trim((string)($project['artifactName']??'digiops-release')) ?: 'digiops-release';
    $artifact=null;
    $run=null;

    if ($runId>0) {
        $run=$client->workflowRun($project['repo'],$runId);
        if (($run['status']??'')!=='completed' || ($run['conclusion']??'')!=='success') {
            throw new RuntimeException('DEPLOY_WORKFLOW_NOT_SUCCESSFUL');
        }
        if ((string)($run['head_branch']??'') !== (string)$project['branch']) {
            throw new RuntimeException('DEPLOY_WORKFLOW_BRANCH_MISMATCH');
        }
        $runCommit=strtolower((string)($run['head_sha']??''));
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
        $commit=strtolower((string)($run['head_sha']??$commit));
    }

    if ($runId<=0 || $artifactId<=0 || !preg_match('/^[a-f0-9]{40}$/',$commit)) throw new RuntimeException('DEPLOY_CANDIDATE_INVALID');
    $artifactDigest=strtolower(trim((string)($artifact['digest']??'')));
    $jobs->patch($requestId,[
        'commit'=>$commit,
        'runId'=>$runId,
        'runNumber'=>(int)($run['run_number']??0),
        'artifactId'=>$artifactId,
        'artifactDigest'=>$artifactDigest,
        'phase'=>'preflight',
        'progress'=>12,
        'verificationSource'=>'control-plane-preflight',
    ]);

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
        foreach(['deploy-chunked','deployment-status','deployment-request-id','atomic-switch-v1'] as $requiredCapability){
            if(!in_array($requiredCapability,$caps,true)) throw new RuntimeException('TARGET_AGENT_UPGRADE_REQUIRED_'.$requiredCapability);
        }
    }

    $jobs->patch($requestId,['state'=>'running','phase'=>'downloading-artifact','progress'=>20,'targetId'=>(string)($target['id']??'local'),'verificationSource'=>'github-artifact']);
    Files::ensureDir(DIGIOPS_PRIVATE_ROOT . '/tmp');
    $zip=DIGIOPS_PRIVATE_ROOT . '/tmp/artifact-' . bin2hex(random_bytes(6)) . '.zip';
    $client->downloadArtifact($project['repo'],$artifactId,$zip,$artifactDigest);
    $artifactBytes=filesize($zip);
    if($artifactBytes===false || $artifactBytes<1) throw new RuntimeException('ARTIFACT_DOWNLOAD_EMPTY');

    $downloadSha=strtolower((string)hash_file('sha256',$zip));
    if(str_starts_with($artifactDigest,'sha256:')){
        $expected=substr($artifactDigest,7);
        if(!preg_match('/^[a-f0-9]{64}$/',$expected) || !hash_equals($expected,$downloadSha)){
            throw new RuntimeException('ARTIFACT_DIGEST_MISMATCH');
        }
    }

    if(($target['id']??'local')==='local'){
        $free=@disk_free_space(dirname(DIGIOPS_PRIVATE_ROOT));
        $minimum=max(64*1024*1024,$artifactBytes*4);
        if(is_float($free) && $free<$minimum) throw new RuntimeException('PREFLIGHT_DISK_SPACE_LOW');
    }

    $jobs->patch($requestId,[
        'phase'=>'artifact-verified',
        'progress'=>30,
        'artifactBytes'=>$artifactBytes,
        'downloadSha256'=>$downloadSha,
        'verificationSource'=>'sha256-integrity',
    ]);

    try {
        if (($target['id']??'local')==='local') {
            $result=(new ReleaseManager())->deployArtifact($projectId,$zip,[
                'commit'=>$commit,
                'artifactId'=>$artifactId,
                'artifactDigest'=>$artifactDigest,
                'downloadSha256'=>$downloadSha,
                'requestId'=>$requestId,
            ],$user);
        } else {
            $jobs->patch($requestId,['state'=>'running','phase'=>'remote-upload','progress'=>34,'verificationSource'=>'remote-target']);
            $result=(new RemoteDeploymentDriver())->deploy($projectId,$zip,$project,[
                'commit'=>$commit,
                'artifactId'=>$artifactId,
                'artifactDigest'=>$artifactDigest,
                'downloadSha256'=>$downloadSha,
                'requestId'=>$requestId,
            ]);
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
                'artifactDigest'=>$artifactDigest,
                'requestId'=>$requestId,
            ],$user);
        }
    } finally {
        @unlink($zip);
    }

    $jobs->patch($requestId,[
        'state'=>'deployed',
        'phase'=>'complete',
        'progress'=>100,
        'release'=>(string)($result['release']??''),
        'completedAt'=>date(DATE_ATOM),
        'verificationSource'=>(($target['id']??'local')==='local'?'local-target':'remote-target'),
    ]);
    JsonResponse::send(['requestId'=>$requestId]+$result);
} catch (Throwable $e) {
    if($jobCreated && preg_match('/^[a-f0-9]{32}$/',$requestId)){
        $message=$e->getMessage();
        $ambiguous=str_starts_with($message,'REMOTE_COMMIT_UNCERTAIN_');
        try{
            $currentJob=$jobs->get($requestId);
            $failurePhase=(string)($currentJob['phase']??'failed');
            $jobs->patch($requestId,$ambiguous ? [
                'state'=>'unavailable',
                'phase'=>'authoritative-confirmation',
                'error'=>$message,
                'verificationSource'=>'remote-commit-response',
            ] : [
                'state'=>'failed',
                'phase'=>$failurePhase!==''?$failurePhase:'failed',
                'progress'=>100,
                'error'=>$message,
                'completedAt'=>date(DATE_ATOM),
                'verificationSource'=>'deploy-api',
            ]);
        }catch(Throwable){}
    }
    $job=null;
    if($jobCreated && preg_match('/^[a-f0-9]{32}$/',$requestId)){
        try{$job=$jobs->get($requestId);}catch(Throwable){}
    }
    JsonResponse::send([
        'error'=>$e->getMessage(),
        'requestId'=>$requestId?:null,
        'state'=>is_array($job)?($job['state']??null):null,
        'phase'=>is_array($job)?($job['phase']??null):null,
        'progress'=>is_array($job)?($job['progress']??null):null,
    ],400);
}
