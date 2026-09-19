<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use DigiOps\Deploy\ReleaseManager;
use DigiOps\Security\Session;
use DigiOps\Support\JsonResponse;

if ($_SERVER['REQUEST_METHOD']!=='POST') JsonResponse::send(['error'=>'METHOD_NOT_ALLOWED'],405);
$user=Session::requireRole(['admin','operator']);
Session::assertCsrf();
$data=json_decode(file_get_contents('php://input') ?: '',true);
if (!is_array($data)) JsonResponse::send(['error'=>'INVALID_JSON'],400);
try { JsonResponse::send((new ReleaseManager())->rollback((string)($data['project']??''),(string)($data['release']??''),$user)); }
catch (Throwable $e) { JsonResponse::send(['error'=>$e->getMessage()],400); }
