<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use DigiOps\Files\ManagedFileBrowser;
use DigiOps\Security\Session;
use DigiOps\Support\JsonResponse;

Session::requireRole();
$id=(string)($_GET['project']??'');
$scope=(string)($_GET['scope']??'public');
$path=(string)($_GET['path']??'');
if (!in_array($scope,['public','private'],true)) JsonResponse::send(['error'=>'INVALID_SCOPE'],400);
try { JsonResponse::send(['ok'=>true,'listing'=>(new ManagedFileBrowser())->list($id,$scope,$path)]); }
catch (Throwable $e) { JsonResponse::send(['error'=>$e->getMessage()],400); }
