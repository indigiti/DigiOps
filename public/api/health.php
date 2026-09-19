<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use DigiOps\Health\HealthService;
use DigiOps\Security\Session;
use DigiOps\Support\JsonResponse;

Session::requireRole();
$id=(string)($_GET['project']??'');
if ($id==='') JsonResponse::send(['error'=>'PROJECT_REQUIRED'],400);
try { JsonResponse::send((new HealthService())->probe($id)); }
catch (Throwable $e) { JsonResponse::send(['error'=>$e->getMessage()],400); }
