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
        $items = [];
        foreach (Files::readJson($this->file, []) as $project) {
            if (!is_array($project)) continue;
            try { $items[] = $this->normalize($project); } catch (\Throwable) {}
        }
        return $items;
    }

    public function find(string $id): ?array
    {
        $id = PathGuard::slug($id);
        foreach ($this->all() as $project) if ($project['id'] === $id) return $project;
        return null;
    }

    public function upsert(array $input): array
    {
        $record = $this->normalize($input);
        if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $record['repo'])) throw new InvalidArgumentException('INVALID_REPOSITORY');
        if (!preg_match('/^[A-Za-z0-9._\/-]{1,160}$/', $record['branch'])) throw new InvalidArgumentException('INVALID_BRANCH');

        $all = $this->all();
        $found = false;
        foreach ($all as $i => $project) {
            if ($project['id'] === $record['id']) { $all[$i] = array_merge($project, $record); $found = true; break; }
        }
        if (!$found) $all[] = $record;
        Files::writeJson($this->file, $all);
        return $record;
    }

    public function delete(string $id): void
    {
        $id = PathGuard::slug($id);
        $all = array_values(array_filter($this->all(), fn(array $p): bool => $p['id'] !== $id));
        Files::writeJson($this->file, $all);
    }

    public function patchRuntime(string $id, array $patch): array
    {
        $current = $this->find($id);
        if (!$current) throw new RuntimeException('PROJECT_NOT_FOUND');
        return $this->upsert(array_merge($current, array_intersect_key($patch, array_flip(['status','health','update','commit','release','lastDeploy','stack','environment']))));
    }

    private function normalize(array $project): array
    {
        $slug = PathGuard::slug((string)($project['id'] ?? $project['slug'] ?? ''));
        return [
            'id' => $slug,
            'name' => trim((string)($project['name'] ?? $slug)) ?: $slug,
            'repo' => trim((string)($project['repo'] ?? '')),
            'branch' => trim((string)($project['branch'] ?? 'main')) ?: 'main',
            'url' => '/' . $slug . '/',
            'publicPath' => PathGuard::publicRelative($slug),
            'privatePath' => PathGuard::privateRelative($slug),
            'status' => (string)($project['status'] ?? 'configured'),
            'health' => (string)($project['health'] ?? 'pending'),
            'update' => (bool)($project['update'] ?? false),
            'commit' => (string)($project['commit'] ?? '—'),
            'release' => (string)($project['release'] ?? 'Not deployed'),
            'lastDeploy' => (string)($project['lastDeploy'] ?? 'Never'),
            'stack' => array_values(array_filter((array)($project['stack'] ?? ['Auto-detect']), 'is_string')),
            'environment' => (string)($project['environment'] ?? 'Stage'),
            'artifactName' => trim((string)($project['artifactName'] ?? 'digiops-release')),
            'healthPath' => trim((string)($project['healthPath'] ?? '/')),
            'retention' => max(1, min(20, (int)($project['retention'] ?? 5))),
        ];
    }
}
