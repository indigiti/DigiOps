<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use DigiOps\Audit\AuditLog;
use DigiOps\Security\Session;
use DigiOps\Support\JsonResponse;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') JsonResponse::send(['error'=>'METHOD_NOT_ALLOWED'],405);
$user=Session::user();
if ($user) {
    Session::assertCsrf();
    (new AuditLog())->write('LOGOUT',[],$user);
}
Session::logout();
JsonResponse::send(['ok'=>true]);
