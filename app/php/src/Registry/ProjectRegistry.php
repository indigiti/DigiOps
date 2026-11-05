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

    // Legacy callers may still register with upsert; never overwrite live runtime state.
    public function upsert(array $input): array
    {
        return $this->create($input);
    }

    public function create(array $input): array
    {
        $record = $this->normalize($input);
        $this->validateRecord($record);

        return Files::withLock($this->file . '.lock', function() use ($record): array {
            $all = $this->allUnlocked();
            foreach ($all as $project) {
                if ($project['id'] === $record['id']) {
                    throw new RuntimeException('PROJECT_ALREADY_EXISTS');
                }
            }
            $all[] = $record;
            Files::writeJson($this->file, $all);
            return $record;
        });
    }

    public function updateConfig(string $id, array $changes): array
    {
        $id = PathGuard::slug($id);
        // The immutable ID determines managed paths. Runtime/deployment metadata
        // must never be accepted from a configuration-edit request.
        $allowed = [
            'name', 'repo', 'branch', 'artifactName', 'healthPath', 'retention',
            'targetId', 'url', 'publicPath', 'privatePath',
        ];
        foreach (array_keys($changes) as $key) {
            if (!in_array($key, $allowed, true) && $key !== 'id') {
                throw new InvalidArgumentException('PROJECT_FIELD_NOT_EDITABLE');
            }
        }
        if (isset($changes['id']) && PathGuard::slug((string)$changes['id']) !== $id) {
            throw new InvalidArgumentException('PROJECT_ID_IMMUTABLE');
        }
        unset($changes['id']);

        return Files::withLock($this->file . '.lock', function() use ($id, $changes): array {
            $all = $this->allUnlocked();
            foreach ($all as $i => $project) {
                if ($project['id'] !== $id) continue;

                // Relocating an already deployed application could orphan live files,
                // releases, health checks or rollback state.
                if (($project['release'] ?? 'Not deployed') !== 'Not deployed') {
                    foreach (['targetId','url','publicPath','privatePath'] as $field) {
                        if (array_key_exists($field, $changes) && (string)$changes[$field] !== (string)$project[$field]) {
                            throw new RuntimeException('DEPLOYED_PROJECT_TARGET_IMMUTABLE');
                        }
                    }
                }
                $updated = $this->normalize(array_merge($project, $changes));
                $this->validateRecord($updated);
                if ($updated['repo'] !== $project['repo'] || $updated['branch'] !== $project['branch'] ||
                    $updated['artifactName'] !== $project['artifactName']) {
                    $updated['update'] = false; // Candidate must be checked again.
                }
                $all[$i] = $updated;
                Files::writeJson($this->file, $all);
                return $updated;
            }
            throw new RuntimeException('PROJECT_NOT_FOUND');
        });
    }

    public function setSuspended(string $id, bool $suspended): array
    {
        $id = PathGuard::slug($id);
        return Files::withLock($this->file . '.lock', function() use ($id, $suspended): array {
            $all = $this->allUnlocked();
            foreach ($all as $i => $project) {
                if ($project['id'] !== $id) continue;
                $project['suspended'] = $suspended;
                $project['suspendedAt'] = $suspended ? date(DATE_ATOM) : null;
                $all[$i] = $project;
                Files::writeJson($this->file, $all);
                return $project;
            }
            throw new RuntimeException('PROJECT_NOT_FOUND');
        });
    }

    // Registry removal deliberately never deletes hosted public/private files.
    public function delete(string $id): void
    {
        $id = PathGuard::slug($id);
        Files::withLock($this->file . '.lock', function() use ($id): void {
            $all = $this->allUnlocked();
            $remaining = array_values(array_filter($all, fn(array $p): bool => $p['id'] !== $id));
            if (count($remaining) === count($all)) throw new RuntimeException('PROJECT_NOT_FOUND');
            Files::writeJson($this->file, $remaining);
        });
    }

    public function patchRuntime(string $id, array $patch): array
    {
        $id = PathGuard::slug($id);
        $allowed = array_flip([
            'status','health','healthCheckedAt','healthDetail','update','commit','release','lastDeploy',
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


    private function normalizeHealthDetail(mixed $value): ?array
    {
        if (!is_array($value)) return null;
        $http=is_array($value['http']??null)?$value['http']:[];
        $storage=is_array($value['storage']??null)?$value['storage']:[];
        $runtime=is_array($value['runtime']??null)?$value['runtime']:[];
        $url=trim((string)($value['url']??''));
        if($url!=='' && !filter_var($url,FILTER_VALIDATE_URL))$url='';

        return [
            'ok'=>(bool)($value['ok']??false),
            'url'=>$url,
            'checkedAt'=>(string)($value['checkedAt']??''),
            'http'=>[
                'ok'=>(bool)($http['ok']??false),
                'status'=>isset($http['status'])?(int)$http['status']:null,
                'ms'=>isset($http['ms'])?(int)$http['ms']:null,
            ],
            'storage'=>[
                'exists'=>(bool)($storage['exists']??false),
                'bytes'=>(int)($storage['bytes']??0),
                'writable'=>(bool)($storage['writable']??false),
            ],
            'runtime'=>[
                'php'=>(string)($runtime['php']??''),
                'curl'=>(bool)($runtime['curl']??false),
                'zip'=>(bool)($runtime['zip']??false),
                'sodium'=>(bool)($runtime['sodium']??false),
            ],
        ];
    }

    private function normalizeRepository(string $id, string $repository): string
    {
        $repository = trim($repository);
        // Migration for the known LexiconSchoolGroup owner typo. This is applied
        // on read and persisted on the next registry write; no GitHub rename.
        if ($id === 'lexiconschoolgroup' && $repository === 'ndigiti/Lexicon-SC-26') {
            return 'indigiti/Lexicon-SC-26';
        }
        return $repository;
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
            'repo' => $this->normalizeRepository($slug, (string)($project['repo'] ?? '')),
            'branch' => trim((string)($project['branch'] ?? 'main')) ?: 'main',
            'url' => $url,
            'publicPath' => $publicPath,
            'privatePath' => $privatePath,
            'status' => (string)($project['status'] ?? 'configured'),
            'health' => (string)($project['health'] ?? 'pending'),
            'healthCheckedAt' => $project['healthCheckedAt'] ?? null,
            'healthDetail' => $this->normalizeHealthDetail($project['healthDetail'] ?? null),
            'update' => (bool)($project['update'] ?? false),
            'commit' => (string)($project['commit'] ?? '—'),
            'release' => (string)($project['release'] ?? 'Not deployed'),
            'lastDeploy' => (string)($project['lastDeploy'] ?? 'Never'),
            'stack' => array_values(array_filter((array)($project['stack'] ?? ['Auto-detect']), 'is_string')),
            'environment' => (string)($project['environment'] ?? 'Stage'),
            'suspended' => (bool)($project['suspended'] ?? false),
            'suspendedAt' => ($project['suspended'] ?? false) ? (string)($project['suspendedAt'] ?? '') : null,
            'targetId' => $targetId,
            'artifactName' => trim((string)($project['artifactName'] ?? 'digiops-release')),
            'healthPath' => trim((string)($project['healthPath'] ?? '/')),
            'retention' => max(1, min(20, (int)($project['retention'] ?? 5))),
        ];
    }
}
