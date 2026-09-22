<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use DigiOps\Audit\AuditLog;
use DigiOps\GitHub\GitHubClient;
use DigiOps\GitHub\DeploymentCandidateSelector;
use DigiOps\Registry\ProjectRegistry;
use DigiOps\Security\SecretVault;
use DigiOps\Security\Session;
use DigiOps\Support\JsonResponse;

$user=Session::requireRole();
$vault=new SecretVault();
$method=$_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method==='POST') {
        if (($user['role']??'')!=='admin') JsonResponse::send(['error'=>'FORBIDDEN'],403);
        Session::assertCsrf();
        $data=json_decode(file_get_contents('php://input') ?: '',true);
        if (!is_array($data)) JsonResponse::send(['error'=>'INVALID_JSON'],400);
        $token=trim((string)($data['token']??''));
        if ($token==='') JsonResponse::send(['error'=>'TOKEN_REQUIRED'],400);
        $test=new GitHubClient($token);
        $repo=trim((string)($data['testRepo']??'indigiti/DigiOps'));
        $test->repo($repo);
        $vault->put('github.token',$token);
        (new AuditLog())->write('GITHUB_CONNECTED',['testRepo'=>$repo],$user);
        JsonResponse::send(['ok'=>true,'connected'=>true]);
    }

    if (!$vault->has('github.token')) JsonResponse::send(['ok'=>true,'connected'=>false]);
    $client=new GitHubClient((string)$vault->get('github.token'));
    $projectId=(string)($_GET['project']??'');
    if ($projectId==='') JsonResponse::send(['ok'=>true,'connected'=>true]);
    $project=(new ProjectRegistry())->find($projectId);
    if (!$project) JsonResponse::send(['error'=>'PROJECT_NOT_FOUND'],404);
    $repo=$client->repo($project['repo']);
    $branches=$client->branches($project['repo']);
    $commits=$client->commits($project['repo'],$project['branch'],50);
    $runs=$client->workflowRuns($project['repo'],$project['branch'],20);
    $workflowRuns=$runs['workflow_runs']??[];

    $latestSha=(string)($commits[0]['sha']??'');
    $deployedCommit=(string)($project['commit']??'');
    if ($deployedCommit==='—') $deployedCommit='';

    $wanted=trim((string)($project['artifactName']??'digiops-release')) ?: 'digiops-release';
    $selection=DeploymentCandidateSelector::find(
        $workflowRuns,
        fn(int $runId): array => $client->artifacts($project['repo'],$runId),
        $wanted
    );
    $successfulRun=$selection['run']??null;
    $candidateArtifact=$selection['artifact']??null;
    $candidateArtifacts=$selection['artifacts']??[];
    $artifactMatch=(string)($selection['match']??'none');

    $candidateSha=(string)($successfulRun['head_sha']??'');
    $deployableReady=$successfulRun!==null && $candidateArtifact!==null && !($candidateArtifact['expired']??false);
    $deployableUpdate=$deployableReady && $candidateSha!=='' && $candidateSha!==$deployedCommit;
    $sourceUpdate=$latestSha!=='' && $latestSha!==$deployedCommit;
    $branchAhead=$candidateSha!=='' && $latestSha!=='' && $candidateSha!==$latestSha;

    $deployedIndex=null;
    $candidateIndex=null;
    foreach ($commits as $index=>$commit) {
        $sha=(string)($commit['sha']??'');
        if ($deployedIndex===null && $deployedCommit!=='' && $sha===$deployedCommit) $deployedIndex=$index;
        if ($candidateIndex===null && $candidateSha!=='' && $sha===$candidateSha) $candidateIndex=$index;
    }
    $sourceCommitsAhead=is_int($deployedIndex) ? $deployedIndex : null;
    $deployableCommitsAhead=(is_int($deployedIndex) && is_int($candidateIndex) && $deployedIndex >= $candidateIndex)
        ? $deployedIndex-$candidateIndex
        : null;

    $candidateCommit=null;
    foreach ($commits as $commit) {
        if (($commit['sha']??'')===$candidateSha) {
            $candidateCommit=$commit;
            break;
        }
    }

    $candidateReason='ready';
    if (!$successfulRun && (int)($selection['successfulRunsChecked']??0)===0) $candidateReason='no-successful-workflow-run';
    elseif (!$candidateArtifact) $candidateReason='configured-artifact-not-found';
    elseif ($candidateArtifact['expired']??false) $candidateReason='artifact-expired';

    (new ProjectRegistry())->patchRuntime($projectId,['update'=>$deployableUpdate]);
    JsonResponse::send([
        'ok'=>true,'connected'=>true,
        'repository'=>[
            'full_name'=>$repo['full_name']??$project['repo'],
            'private'=>$repo['private']??null,
            'default_branch'=>$repo['default_branch']??null,
            'visibility'=>$repo['visibility']??null,
            'updatedAt'=>$repo['updated_at']??null,
        ],
        'branchHead'=>[
            'branch'=>$project['branch'],
            'sha'=>$latestSha,
            'message'=>$commits[0]['commit']['message']??'',
            'date'=>$commits[0]['commit']['committer']['date']??null,
            'author'=>$commits[0]['commit']['author']['name']??null,
        ],
        'deployed'=>[
            'commit'=>$deployedCommit,
            'release'=>$project['release']??'Not deployed',
            'lastDeploy'=>$project['lastDeploy']??'Never',
            'health'=>$project['health']??'pending',
        ],
        'candidate'=>[
            'ready'=>$deployableReady,
            'reason'=>$candidateReason,
            'updateAvailable'=>$deployableUpdate,
            'alreadyDeployed'=>$deployableReady && $candidateSha!=='' && $candidateSha===$deployedCommit,
            'branchAhead'=>$branchAhead,
            'sourceCommitsAhead'=>$sourceCommitsAhead,
            'deployableCommitsAhead'=>$deployableCommitsAhead,
            'run'=>$successfulRun ? [
                'id'=>$successfulRun['id']??null,
                'number'=>$successfulRun['run_number']??null,
                'attempt'=>$successfulRun['run_attempt']??null,
                'workflowId'=>$successfulRun['workflow_id']??null,
                'name'=>$successfulRun['name']??'',
                'title'=>$successfulRun['display_title']??'',
                'event'=>$successfulRun['event']??'',
                'status'=>$successfulRun['status']??'',
                'conclusion'=>$successfulRun['conclusion']??null,
                'sha'=>$candidateSha,
                'branch'=>$successfulRun['head_branch']??$project['branch'],
                'createdAt'=>$successfulRun['created_at']??null,
                'updatedAt'=>$successfulRun['updated_at']??null,
                'url'=>$successfulRun['html_url']??null,
            ] : null,
            'artifact'=>$candidateArtifact ? [
                'id'=>$candidateArtifact['id']??null,
                'name'=>$candidateArtifact['name']??'',
                'sizeBytes'=>$candidateArtifact['size_in_bytes']??null,
                'digest'=>$candidateArtifact['digest']??null,
                'createdAt'=>$candidateArtifact['created_at']??null,
                'updatedAt'=>$candidateArtifact['updated_at']??null,
                'expiresAt'=>$candidateArtifact['expires_at']??null,
                'expired'=>$candidateArtifact['expired']??false,
                'match'=>$artifactMatch,
            ] : null,
            'commit'=>[
                'sha'=>$candidateSha,
                'message'=>$candidateCommit['commit']['message']??($successfulRun['head_commit']['message']??''),
                'date'=>$candidateCommit['commit']['committer']['date']??($successfulRun['head_commit']['timestamp']??null),
                'author'=>$candidateCommit['commit']['author']['name']??($successfulRun['head_commit']['author']['name']??null),
            ],
            'artifactCount'=>count($candidateArtifacts),
            'successfulRunsChecked'=>(int)($selection['successfulRunsChecked']??0),
        ],
        'branches'=>array_map(fn($b)=>['name'=>$b['name']??'','sha'=>$b['commit']['sha']??''],$branches),
        'commits'=>array_map(fn($c)=>[
            'sha'=>$c['sha']??'',
            'message'=>$c['commit']['message']??'',
            'date'=>$c['commit']['committer']['date']??null,
            'author'=>$c['commit']['author']['name']??null
        ],$commits),
        'runs'=>array_map(fn($r)=>[
            'id'=>$r['id']??null,
            'number'=>$r['run_number']??null,
            'attempt'=>$r['run_attempt']??null,
            'name'=>$r['name']??'',
            'title'=>$r['display_title']??'',
            'event'=>$r['event']??'',
            'status'=>$r['status']??'',
            'conclusion'=>$r['conclusion']??null,
            'sha'=>$r['head_sha']??'',
            'createdAt'=>$r['created_at']??null,
            'updatedAt'=>$r['updated_at']??null
        ],$workflowRuns),
        'updateAvailable'=>$deployableUpdate,
        'sourceUpdateAvailable'=>$sourceUpdate,
    ]);
} catch (Throwable $e) {
    $error=$e->getMessage();
    $stage=null;
    if (preg_match('/^GITHUB_([A-Z]+)_(?:HTTP_\d{3}|FAILED)$/', $error, $match)) {
        $stage=strtolower($match[1]);
    }
    JsonResponse::send([
        'error'=>$error,
        'github'=>[
            'stage'=>$stage,
            'repository'=>isset($project['repo']) ? $project['repo'] : null,
            'branch'=>isset($project['branch']) ? $project['branch'] : null,
        ],
    ],400);
}
