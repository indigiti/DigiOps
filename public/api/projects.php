<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use DigiOps\Registry\ProjectRegistry;
use DigiOps\Support\JsonResponse;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    JsonResponse::send(['error' => 'METHOD_NOT_ALLOWED'], 405);
}

$registry = new ProjectRegistry();
JsonResponse::send([
    'ok' => true,
    'projects' => $registry->all(),
    'privateConfigured' => is_dir(DIGIOPS_PRIVATE_ROOT),
]);
