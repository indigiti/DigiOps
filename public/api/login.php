<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use DigiOps\Audit\AuditLog;
use DigiOps\Security\RateLimiter;
use DigiOps\Security\Session;
use DigiOps\Security\UserStore;
use DigiOps\Support\JsonResponse;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') JsonResponse::send(['error'=>'METHOD_NOT_ALLOWED'],405);
$key='login:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!RateLimiter::allow($key, 12, 900)) JsonResponse::send(['error'=>'RATE_LIMITED'],429);
$data=json_decode(file_get_contents('php://input') ?: '',true);
if (!is_array($data)) JsonResponse::send(['error'=>'INVALID_JSON'],400);
$user=(new UserStore())->verify((string)($data['username']??''),(string)($data['password']??''),isset($data['totp'])?(string)$data['totp']:null);
if (!$user) {
    (new AuditLog())->write('LOGIN_FAILED',['username'=>(string)($data['username']??'')],null);
    JsonResponse::send(['error'=>'INVALID_CREDENTIALS'],401);
}
Session::login($user);
(new AuditLog())->write('LOGIN_SUCCESS',[],$user);
JsonResponse::send(['ok'=>true,'user'=>$user,'csrf'=>Session::csrf()]);
