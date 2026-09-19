<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use DigiOps\Audit\AuditLog;
use DigiOps\Security\Session;
use DigiOps\Support\JsonResponse;

Session::requireRole(['admin']);
JsonResponse::send(['ok'=>true,'events'=>(new AuditLog())->recent((int)($_GET['limit']??100))]);
