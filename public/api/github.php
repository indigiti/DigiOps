<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use DigiOps\Audit\AuditLog;
use DigiOps\GitHub\GitHubClient;
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
    $commits=$client->commits($project['repo'],$project['branch'],20);
    $runs=$client->workflowRuns($project['repo'],$project['branch'],10);
    $latestSha=(string)($commits[0]['sha']??'');
    $update=$latestSha!=='' && $latestSha!==($project['commit']??'');
    (new ProjectRegistry())->patchRuntime($projectId,['update'=>$update]);
    JsonResponse::send([
        'ok'=>true,'connected'=>true,
        'repository'=>['full_name'=>$repo['full_name']??$project['repo'],'private'=>$repo['private']??null,'default_branch'=>$repo['default_branch']??null],
        'branches'=>array_map(fn($b)=>['name'=>$b['name']??'','sha'=>$b['commit']['sha']??''],$branches),
        'commits'=>array_map(fn($c)=>['sha'=>$c['sha']??'','message'=>$c['commit']['message']??'','date'=>$c['commit']['committer']['date']??null,'author'=>$c['commit']['author']['name']??null],$commits),
        'runs'=>array_map(fn($r)=>['id'=>$r['id']??null,'name'=>$r['name']??'','status'=>$r['status']??'','conclusion'=>$r['conclusion']??null,'sha'=>$r['head_sha']??'','createdAt'=>$r['created_at']??null],$runs['workflow_runs']??[]),
        'updateAvailable'=>$update,
    ]);
} catch (Throwable $e) {
    JsonResponse::send(['error'=>$e->getMessage()],400);
}
