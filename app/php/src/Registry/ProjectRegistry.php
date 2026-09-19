<?php
declare(strict_types=1);

namespace DigiOps\Registry;

use DigiOps\Security\PathGuard;

final class ProjectRegistry
{
    private string $file;

    public function __construct(?string $file = null)
    {
        $this->file = $file ?: DIGIOPS_PRIVATE_ROOT . '/registry/projects.json';
    }

    public function all(): array
    {
        if (!is_file($this->file)) {
            return [];
        }

        $raw = file_get_contents($this->file);
        $decoded = json_decode($raw ?: '[]', true);
        if (!is_array($decoded)) return [];

        $items = [];
        foreach ($decoded as $project) {
            if (!is_array($project)) continue;
            try {
                $slug = PathGuard::slug((string)($project['id'] ?? ''));
            } catch (\Throwable) {
                continue;
            }
            $items[] = [
                'id' => $slug,
                'name' => (string)($project['name'] ?? $slug),
                'repo' => (string)($project['repo'] ?? ''),
                'branch' => (string)($project['branch'] ?? 'main'),
                'url' => '/' . $slug . '/',
                'publicPath' => PathGuard::publicRelative($slug),
                'privatePath' => PathGuard::privateRelative($slug),
                'status' => (string)($project['status'] ?? 'configured'),
                'health' => (string)($project['health'] ?? 'pending'),
                'update' => (bool)($project['update'] ?? false),
                'commit' => (string)($project['commit'] ?? '—'),
                'release' => (string)($project['release'] ?? 'Not deployed'),
                'lastDeploy' => (string)($project['lastDeploy'] ?? 'Never'),
                'stack' => array_values(array_filter((array)($project['stack'] ?? []), 'is_string')),
                'environment' => (string)($project['environment'] ?? 'Stage'),
            ];
        }
        return $items;
    }
}
