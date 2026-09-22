<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use DigiOps\Audit\AuditLog;
use DigiOps\Security\RateLimiter;
use DigiOps\Security\Session;
use DigiOps\Security\UserStore;
use DigiOps\Support\Files;
use DigiOps\Support\JsonResponse;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') JsonResponse::send(['error'=>'METHOD_NOT_ALLOWED'],405);
if (!RateLimiter::allow('install:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 10, 3600)) JsonResponse::send(['error'=>'RATE_LIMITED'],429);
$store = new UserStore();

$data = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($data)) JsonResponse::send(['error'=>'INVALID_JSON'],400);

try {
    Files::ensureDir(DIGIOPS_PRIVATE_ROOT, 0750);
    foreach (['users','vault','registry','projects','audit','rate','tmp'] as $dir) Files::ensureDir(DIGIOPS_PRIVATE_ROOT . '/' . $dir, 0750);
    $user = $store->createInitialAdmin((string)($data['username'] ?? ''),(string)($data['name'] ?? ''),(string)($data['password'] ?? ''),($data['totpSecret'] ?? null) ?: null);
    Session::login($user);
    (new AuditLog())->write('INSTALL_COMPLETE',['privateRoot'=>DIGIOPS_PRIVATE_ROOT],$user);
    JsonResponse::send(['ok'=>true,'user'=>$user,'csrf'=>Session::csrf()]);
} catch (Throwable $e) {
    $error=$e->getMessage();
    $status=$error==='ALREADY_INSTALLED' ? 409 : (in_array($error,['USER_STORE_INVALID','USER_STORE_READ_FAILED'],true) ? 500 : 400);
    JsonResponse::send(['error'=>$error],$status);
}
