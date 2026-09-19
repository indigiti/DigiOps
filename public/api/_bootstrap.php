<?php
declare(strict_types=1);

$candidates = [
    getenv('DIGIOPS_BOOTSTRAP') ?: '',
    dirname(__DIR__, 2) . '/app/php/bootstrap.php',
];

$documentRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: '';
if ($documentRoot !== '') {
    $candidates[] = dirname($documentRoot) . '/private_html/digiops/app/php/bootstrap.php';
}

foreach ($candidates as $candidate) {
    if ($candidate !== '' && is_file($candidate)) {
        require_once $candidate;
        return;
    }
}

http_response_code(500);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['error' => 'DIGIOPS_BACKEND_NOT_CONFIGURED']);
exit;
