<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use DigiOps\Audit\AuditLog;
use DigiOps\Registry\ProjectRegistry;
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
