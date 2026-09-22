<?php
declare(strict_types=1);

namespace DigiOps\Registry;

use DigiOps\Security\PathGuard;
use DigiOps\Support\Files;
use InvalidArgumentException;
use RuntimeException;

final class ProjectRegistry
{
    private string $file;

    public function __construct(?string $file = null)
    {
        $this->file = $file ?: DIGIOPS_PRIVATE_ROOT . '/registry/projects.json';
    }

    public function all(): array
    {
        return $this->allUnlocked();
    }

    public function find(string $id): ?array
    {
        $id = PathGuard::slug($id);
        foreach ($this->allUnlocked() as $project) if ($project['id'] === $id) return $project;
        return null;
    }

    public function upsert(array $input): array
    {
        $record = $this->normalize($input);
        $this->validateRecord($record);

        Files::withLock($this->file . '.lock', function() use ($record): void {
            $all = $this->allUnlocked();
            $found = false;
            foreach ($all as $i => $project) {
                if ($project['id'] === $record['id']) {
                    $all[$i] = array_merge($project, $record);
                    $found = true;
                    break;
                }
            }
            if (!$found) $all[] = $record;
            Files::writeJson($this->file, $all);
        });

        return $record;
    }

    public function delete(string $id): void
    {
        $id = PathGuard::slug($id);
        Files::withLock($this->file . '.lock', function() use ($id): void {
            $all = array_values(array_filter($this->allUnlocked(), fn(array $p): bool => $p['id'] !== $id));
            Files::writeJson($this->file, $all);
        });
    }

    public function patchRuntime(string $id, array $patch): array
    {
        $id = PathGuard::slug($id);
        $allowed = array_flip([
            'status','health','healthCheckedAt','update','commit','release','lastDeploy',
            'stack','environment'
        ]);

        return Files::withLock($this->file . '.lock', function() use ($id,$patch,$allowed): array {
            $all = $this->allUnlocked();
            $updated = null;
            foreach ($all as $i => $project) {
                if ($project['id'] !== $id) continue;
                $updated = $this->normalize(array_merge($project, array_intersect_key($patch, $allowed)));
                $this->validateRecord($updated);
                $all[$i] = $updated;
                break;
            }
            if ($updated === null) throw new RuntimeException('PROJECT_NOT_FOUND');
            Files::writeJson($this->file, $all);
            return $updated;
        });
    }

    private function allUnlocked(): array
    {
        $items = [];
        foreach (Files::readJsonStrict($this->file, []) as $project) {
            if (!is_array($project)) throw new RuntimeException('PROJECT_REGISTRY_INVALID');
            try { $items[] = $this->normalize($project); }
            catch (\Throwable $e) { throw new RuntimeException('PROJECT_REGISTRY_INVALID',0,$e); }
        }
        return $items;
    }

    private function validateRecord(array $record): void
    {
        if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $record['repo'])) throw new InvalidArgumentException('INVALID_REPOSITORY');
        if (!preg_match('/^[A-Za-z0-9._\/-]{1,160}$/', $record['branch'])) throw new InvalidArgumentException('INVALID_BRANCH');
    }

    private function normalize(array $project): array
    {
        $slug = PathGuard::slug((string)($project['id'] ?? $project['slug'] ?? ''));
        $targetId = PathGuard::slug((string)($project['targetId'] ?? 'local'));
        $url = '/' . $slug . '/';
        $publicPath = PathGuard::publicRelative($slug);
        $privatePath = PathGuard::privateRelative($slug);

        if ($targetId !== 'local') {
            $candidateUrl = trim((string)($project['url'] ?? ''));
            if ($candidateUrl !== '') {
                if (!filter_var($candidateUrl, FILTER_VALIDATE_URL) || strtolower((string)parse_url($candidateUrl, PHP_URL_SCHEME)) !== 'https') {
                    throw new InvalidArgumentException('REMOTE_HTTPS_URL_REQUIRED');
                }
                $url = rtrim($candidateUrl, '/') . '/';
            }
            $publicPath = PathGuard::targetRelative((string)($project['publicPath'] ?? ('public_html/' . $slug . '/')), 'public');
            $privatePath = PathGuard::targetRelative((string)($project['privatePath'] ?? ('private_html/' . $slug . '/')), 'private');
        }

        return [
            'id' => $slug,
            'name' => trim((string)($project['name'] ?? $slug)) ?: $slug,
            'repo' => trim((string)($project['repo'] ?? '')),
            'branch' => trim((string)($project['branch'] ?? 'main')) ?: 'main',
            'url' => $url,
            'publicPath' => $publicPath,
            'privatePath' => $privatePath,
            'status' => (string)($project['status'] ?? 'configured'),
            'health' => (string)($project['health'] ?? 'pending'),
            'healthCheckedAt' => $project['healthCheckedAt'] ?? null,
            'update' => (bool)($project['update'] ?? false),
            'commit' => (string)($project['commit'] ?? '—'),
            'release' => (string)($project['release'] ?? 'Not deployed'),
            'lastDeploy' => (string)($project['lastDeploy'] ?? 'Never'),
            'stack' => array_values(array_filter((array)($project['stack'] ?? ['Auto-detect']), 'is_string')),
            'environment' => (string)($project['environment'] ?? 'Stage'),
            'targetId' => $targetId,
            'artifactName' => trim((string)($project['artifactName'] ?? 'digiops-release')),
            'healthPath' => trim((string)($project['healthPath'] ?? '/')),
            'retention' => max(1, min(20, (int)($project['retention'] ?? 5))),
        ];
    }
}
