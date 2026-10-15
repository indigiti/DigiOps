<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use DigiOps\Audit\AuditLog;
use DigiOps\Registry\ProjectRegistry;
use DigiOps\GitHub\GitHubClient;
use DigiOps\Security\SecretVault;
use DigiOps\Security\Session;
use DigiOps\Support\JsonResponse;

$registry=new ProjectRegistry();
$method=$_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method==='GET') {
    Session::requireRole();
    JsonResponse::send(['ok'=>true,'projects'=>$registry->all()]);
}
$user=Session::requireRole(['admin','operator']);
Session::assertCsrf();
$data=json_decode(file_get_contents('php://input') ?: '',true);
if (!is_array($data)) JsonResponse::send(['error'=>'INVALID_JSON'],400);

try {
    if ($method==='PATCH' && ($data['action']??'')==='set-deployment-branch') {
        if (($user['role']??'')!=='admin') JsonResponse::send(['error'=>'FORBIDDEN'],403);
        $id=(string)($data['id']??'');
        $current=$registry->find($id);
        if (!$current) JsonResponse::send(['error'=>'PROJECT_NOT_FOUND'],404);
        $newBranch=trim((string)($data['branch']??''));
        $expectedBranch=(string)($data['expectedBranch']??'');
        if ($expectedBranch==='' || $current['branch']!==$expectedBranch) {
            JsonResponse::send(['error'=>'DEPLOY_BRANCH_CHANGED_RETRY'],409);
        }
        // Verify the requested source is a real GitHub branch, not just an
        // arbitrary workflow run or SHA. Do not modify the active deployment.
        $token=(new SecretVault())->get('github.token');
        if (!$token) JsonResponse::send(['error'=>'GITHUB_NOT_CONNECTED'],400);
        $branches=(new GitHubClient((string)$token))->branches($current['repo']);
        $exists=false;
        foreach ($branches as $candidate) {
            if (is_array($candidate) && ($candidate['name']??null)===$newBranch) {
                $exists=true;
                break;
            }
        }
        if (!$exists) JsonResponse::send(['error'=>'GITHUB_BRANCH_NOT_FOUND'],400);
        $updated=$registry->setDeploymentBranch($id,$expectedBranch,$newBranch);
        (new AuditLog())->write('PROJECT_DEPLOY_BRANCH_CHANGED',[
            'project'=>$updated['id'],
            'repo'=>$updated['repo'],
            'before'=>$expectedBranch,
            'after'=>$newBranch,
            'deployedCommit'=>$updated['commit'],
        ],$user);
        JsonResponse::send(['ok'=>true,'project'=>$updated,'deployed'=>false]);
    }
    if ($method==='POST' || $method==='PUT') {
        $project=$registry->upsert($data);
        (new AuditLog())->write('PROJECT_UPSERT',['project'=>$project['id'],'repo'=>$project['repo'],'targetId'=>$project['targetId']??'local'],$user);
        JsonResponse::send(['ok'=>true,'project'=>$project]);
    }
    if ($method==='DELETE') {
        $id=(string)($data['id']??'');
        $registry->delete($id);
        (new AuditLog())->write('PROJECT_DELETE',['project'=>$id],$user);
        JsonResponse::send(['ok'=>true]);
    }
    JsonResponse::send(['error'=>'METHOD_NOT_ALLOWED'],405);
} catch (Throwable $e) {
    JsonResponse::send(['error'=>$e->getMessage()],400);
}
