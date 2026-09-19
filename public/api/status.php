<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use DigiOps\Support\JsonResponse;

JsonResponse::send([
    'ok' => true,
    'service' => 'DigiOps',
    'version' => '0.1.0',
    'php' => PHP_VERSION,
    'privateConfigured' => is_dir(DIGIOPS_PRIVATE_ROOT),
    'time' => date(DATE_ATOM),
]);
