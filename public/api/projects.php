<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use DigiOps\Audit\AuditLog;
use DigiOps\Deploy\DeploymentJobRepository;
use DigiOps\Registry\ProjectRegistry;
use DigiOps\Security\Session;
use DigiOps\Support\JsonResponse;

$registry = new ProjectRegistry();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    Session::requireRole();
    JsonResponse::send(['ok' => true, 'projects' => $registry->all()]);
}

$user = Session::requireRole();
Session::assertCsrf();
$data = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($data)) JsonResponse::send(['error' => 'INVALID_JSON'], 400);

try {
    if ($method === 'POST') {
        if (!in_array(($user['role'] ?? ''), ['admin', 'operator'], true)) JsonResponse::send(['error' => 'FORBIDDEN'], 403);
        $project = $registry->create($data);
        (new AuditLog())->write('PROJECT_CREATED', ['project' => $project['id'], 'repo' => $project['repo'], 'targetId' => $project['targetId']], $user);
        JsonResponse::send(['ok' => true, 'project' => $project], 201);
    }
    if ($method === 'PUT') {
        if (!in_array(($user['role'] ?? ''), ['admin', 'operator'], true)) JsonResponse::send(['error' => 'FORBIDDEN'], 403);
        $id = (string)($data['id'] ?? '');
        $existing = $registry->find($id);
        if (!$existing) JsonResponse::send(['error' => 'PROJECT_NOT_FOUND'], 404);
        foreach ((new DeploymentJobRepository())->active($id) as $job) {
            JsonResponse::send(['error' => 'DEPLOYMENT_ALREADY_ACTIVE', 'requestId' => $job['requestId'] ?? ''], 409);
        }
        $project = $registry->updateConfig($id, $data);
        (new AuditLog())->write('PROJECT_CONFIG_UPDATED', [
            'project' => $id,
            'fromRepo' => $existing['repo'],
            'toRepo' => $project['repo'],
            'fromBranch' => $existing['branch'],
            'toBranch' => $project['branch']
        ], $user);
        JsonResponse::send(['ok' => true, 'project' => $project]);
    }
    if ($method === 'PATCH') {
        if (($user['role'] ?? '') !== 'admin') JsonResponse::send(['error' => 'FORBIDDEN'], 403);
        $id = (string)($data['id'] ?? '');
        if (!isset($data['suspended']) || !is_bool($data['suspended'])) JsonResponse::send(['error' => 'SUSPENDED_BOOLEAN_REQUIRED'], 400);
        foreach ((new DeploymentJobRepository())->active($id) as $job) {
            JsonResponse::send(['error' => 'DEPLOYMENT_ALREADY_ACTIVE', 'requestId' => $job['requestId'] ?? ''], 409);
        }
        $project = $registry->setSuspended($id, $data['suspended']);
        (new AuditLog())->write($project['suspended'] ? 'PROJECT_SUSPENDED' : 'PROJECT_REACTIVATED', [
            'project' => $id, 'scope' => 'digiops-management-and-new-deployments'
        ], $user);
        JsonResponse::send(['ok' => true, 'project' => $project]);
    }
    if ($method === 'DELETE') {
        if (($user['role'] ?? '') !== 'admin') JsonResponse::send(['error' => 'FORBIDDEN'], 403);
        $id = (string)($data['id'] ?? '');
        $project = $registry->find($id);
        if (!$project) JsonResponse::send(['error' => 'PROJECT_NOT_FOUND'], 404);
        if (!$project['suspended']) JsonResponse::send(['error' => 'SUSPEND_PROJECT_FIRST'], 409);
        if (($data['confirmation'] ?? '') !== $id) JsonResponse::send(['error' => 'PROJECT_ID_CONFIRMATION_REQUIRED'], 400);
        foreach ((new DeploymentJobRepository())->active($id) as $job) {
            JsonResponse::send(['error' => 'DEPLOYMENT_ALREADY_ACTIVE', 'requestId' => $job['requestId'] ?? ''], 409);
        }
        $registry->delete($id);
        (new AuditLog())->write('PROJECT_REMOVED_FROM_REGISTRY', [
            'project' => $id, 'repo' => $project['repo'], 'hostedFilesDeleted' => false
        ], $user);
        JsonResponse::send(['ok' => true, 'hostedFilesDeleted' => false]);
    }
    JsonResponse::send(['error' => 'METHOD_NOT_ALLOWED'], 405);
} catch (Throwable $e) {
    JsonResponse::send(['error' => $e->getMessage()], 400);
}
