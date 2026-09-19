<?php
declare(strict_types=1);

namespace DigiOps\Deploy;

use DigiOps\Audit\AuditLog;
use DigiOps\Registry\ProjectRegistry;
use DigiOps\Security\PathGuard;
use DigiOps\Support\Files;
use RuntimeException;
use ZipArchive;

final class ReleaseManager
{
    public function __construct(
        private ProjectRegistry $projects = new ProjectRegistry(),
        private AuditLog $audit = new AuditLog()
    ) {}

    public function deployArtifact(string $projectId, string $zipFile, array $meta, array $user): array
    {
        $project = $this->projects->find($projectId);
        if (!$project) throw new RuntimeException('PROJECT_NOT_FOUND');
        if (!is_file($zipFile)) throw new RuntimeException('ARTIFACT_MISSING');

        $slug = PathGuard::slug($projectId);
        $runtime = DIGIOPS_PRIVATE_ROOT . '/projects/' . $slug;
        $lockFile = $runtime . '/deploy.lock';
        Files::ensureDir($runtime);
        $lock = fopen($lockFile, 'c+');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('DEPLOYMENT_LOCKED');

        try {
            $releaseId = date('Ymd-His') . '-' . substr((string)($meta['commit'] ?? bin2hex(random_bytes(4))), 0, 8);
            $stage = $runtime . '/staging/' . $releaseId;
            $releases = $runtime . '/releases';
            $releaseDir = $releases . '/' . $releaseId;
            $public = DIGIOPS_APP_HOME . '/' . $project['publicPath'];
            Files::ensureDir($stage);
            Files::ensureDir($releases);

            $this->extractSafe($zipFile, $stage);
            $payload = $this->detectPayload($stage);
            $this->validatePayload($payload);

            if (is_dir($public) && $this->hasEntries($public)) {
                $backupId = 'pre-' . $releaseId;
                $backupDir = $releases . '/' . $backupId;
                Files::copyDir($public, $backupDir . '/payload');
                Files::writeJson($backupDir . '/meta.json', ['id'=>$backupId,'type'=>'snapshot','createdAt'=>date(DATE_ATOM),'source'=>'pre-deploy']);
            }

            if (is_dir($releaseDir)) Files::removeTree($releaseDir);
            Files::ensureDir($releaseDir);
            Files::copyDir($payload, $releaseDir . '/payload');
            Files::writeJson($releaseDir . '/meta.json', [
                'id'=>$releaseId,
                'type'=>'release',
                'project'=>$slug,
                'commit'=>(string)($meta['commit'] ?? ''),
                'artifactId'=>(string)($meta['artifactId'] ?? ''),
                'createdAt'=>date(DATE_ATOM),
                'sha256'=>hash_file('sha256', $zipFile),
            ]);

            $publishTmp = dirname($public) . '/.' . $slug . '.publish-' . bin2hex(random_bytes(4));
            Files::copyDir($releaseDir . '/payload', $publishTmp);
            if (is_dir($public)) Files::removeTree($public);
            if (!rename($publishTmp, $public)) throw new RuntimeException('PUBLISH_RENAME_FAILED');

            $this->projects->patchRuntime($slug, [
                'status'=>'deployed',
                'health'=>'pending',
                'commit'=>(string)($meta['commit'] ?? '—'),
                'release'=>$releaseId,
                'lastDeploy'=>date(DATE_ATOM),
                'update'=>false,
            ]);
            $this->prune($slug, (int)$project['retention']);
            $this->audit->write('DEPLOY_SUCCESS', ['project'=>$slug,'release'=>$releaseId,'commit'=>$meta['commit'] ?? null], $user);
            return ['ok'=>true,'release'=>$releaseId,'publicPath'=>$project['publicPath']];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function rollback(string $projectId, string $releaseId, array $user): array
    {
        $project = $this->projects->find($projectId);
        if (!$project) throw new RuntimeException('PROJECT_NOT_FOUND');
        $slug = PathGuard::slug($projectId);
        if (!preg_match('/^[A-Za-z0-9._-]{3,100}$/', $releaseId)) throw new RuntimeException('INVALID_RELEASE_ID');
        $source = DIGIOPS_PRIVATE_ROOT . '/projects/' . $slug . '/releases/' . $releaseId . '/payload';
        if (!is_dir($source)) throw new RuntimeException('RELEASE_NOT_FOUND');
        $public = DIGIOPS_APP_HOME . '/' . $project['publicPath'];
        $tmp = dirname($public) . '/.' . $slug . '.rollback-' . bin2hex(random_bytes(4));
        Files::copyDir($source, $tmp);
        if (is_dir($public)) Files::removeTree($public);
        if (!rename($tmp, $public)) throw new RuntimeException('ROLLBACK_FAILED');
        $meta = Files::readJson(dirname($source) . '/meta.json', []);
        $this->projects->patchRuntime($slug, [
            'status'=>'deployed',
            'health'=>'pending',
            'release'=>$releaseId,
            'commit'=>(string)($meta['commit'] ?? '—'),
            'lastDeploy'=>date(DATE_ATOM),
        ]);
        $this->audit->write('ROLLBACK_SUCCESS', ['project'=>$slug,'release'=>$releaseId], $user);
        return ['ok'=>true,'release'=>$releaseId];
    }

    public function releases(string $projectId): array
    {
        $slug = PathGuard::slug($projectId);
        $dir = DIGIOPS_PRIVATE_ROOT . '/projects/' . $slug . '/releases';
        if (!is_dir($dir)) return [];
        $out = [];
        foreach (array_diff(scandir($dir) ?: [], ['.','..']) as $name) {
            $path = $dir . '/' . $name;
            if (!is_dir($path)) continue;
            $meta = Files::readJson($path . '/meta.json', ['id'=>$name]);
            $meta['size'] = Files::directorySize($path . '/payload');
            $out[] = $meta;
        }
        usort($out, fn($a,$b)=>strcmp((string)($b['createdAt'] ?? ''),(string)($a['createdAt'] ?? '')));
        return $out;
    }

    private function extractSafe(string $zipFile, string $target): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) throw new RuntimeException('INVALID_ZIP');
        for ($i=0; $i<$zip->numFiles; $i++) {
            $name = str_replace('\\','/',$zip->getNameIndex($i));
            if ($name === '' || str_starts_with($name,'/') || str_contains($name,'../') || preg_match('#^[A-Za-z]:/#',$name)) {
                $zip->close(); throw new RuntimeException('ZIP_PATH_TRAVERSAL');
            }
        }
        if (!$zip->extractTo($target)) { $zip->close(); throw new RuntimeException('ZIP_EXTRACT_FAILED'); }
        $zip->close();
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($target, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $item) if ($item->isLink()) throw new RuntimeException('SYMLINK_NOT_ALLOWED');
    }

    private function detectPayload(string $stage): string
    {
        if (is_dir($stage . '/dist')) return $stage . '/dist';
        if (is_dir($stage . '/public')) return $stage . '/public';
        $entries = array_values(array_filter(array_diff(scandir($stage) ?: [], ['.','..']), fn($x)=>$x !== '__MACOSX'));
        if (count($entries) === 1 && is_dir($stage . '/' . $entries[0])) {
            $root = $stage . '/' . $entries[0];
            if (is_dir($root . '/dist')) return $root . '/dist';
            if (is_file($root . '/index.html') || is_file($root . '/index.php')) return $root;
        }
        return $stage;
    }

    private function validatePayload(string $payload): void
    {
        if (!is_dir($payload)) throw new RuntimeException('PAYLOAD_MISSING');
        if (!is_file($payload . '/index.html') && !is_file($payload . '/index.php')) throw new RuntimeException('ENTRYPOINT_MISSING');
        if (Files::directorySize($payload) > 1024 * 1024 * 1024) throw new RuntimeException('PAYLOAD_TOO_LARGE');
    }

    private function hasEntries(string $dir): bool
    {
        $list = array_diff(scandir($dir) ?: [], ['.','..']);
        return count($list) > 0;
    }

    private function prune(string $slug, int $keep): void
    {
        $dir = DIGIOPS_PRIVATE_ROOT . '/projects/' . $slug . '/releases';
        if (!is_dir($dir)) return;
        $items = [];
        foreach (array_diff(scandir($dir) ?: [], ['.','..']) as $name) {
            $path = $dir . '/' . $name;
            if (is_dir($path)) $items[$name] = filemtime($path) ?: 0;
        }
        arsort($items);
        $i=0;
        foreach ($items as $name=>$mtime) {
            $i++;
            if ($i > max(2,$keep)) Files::removeTree($dir . '/' . $name);
        }
    }
}
