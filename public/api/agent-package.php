<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use DigiOps\Security\Session;

$user=Session::requireRole(['admin']);
$file=DIGIOPS_SOURCE_ROOT . '/agent/digiops-agent.php';
if(!is_file($file)){
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error'=>'AGENT_PACKAGE_NOT_FOUND']);
    exit;
}
header('Content-Type: application/x-httpd-php');
header('Content-Disposition: attachment; filename="digiops-agent.php"');
header('Content-Length: '.filesize($file));
header('Cache-Control: no-store, private');
readfile($file);
exit;
