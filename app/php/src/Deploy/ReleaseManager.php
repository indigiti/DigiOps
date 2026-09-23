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
        private AuditLog $audit = new AuditLog(),
        private AtomicReleaseSwitcher $switcher = new AtomicReleaseSwitcher(),
        private DeploymentJobRepository $jobs = new DeploymentJobRepository()
    ) {}

    public function deploymentState(string $projectId): array
    {
        $slug=PathGuard::slug($projectId);
        return Files::readJson(DIGIOPS_PRIVATE_ROOT . '/projects/' . $slug . '/deployment.json', []);
    }

    private function writeDeploymentState(string $slug,string $state,string $phase,int $progress,array $extra=[]): void
    {
        $file=DIGIOPS_PRIVATE_ROOT . '/projects/' . $slug . '/deployment.json';
        $previous=Files::readJson($file, []);
        $now=date(DATE_ATOM);
        $payload=[
            'state'=>$state,
            'phase'=>$phase,
            'progress'=>max(0,min(100,$progress)),
            'startedAt'=>(string)($extra['startedAt']??($previous['startedAt']??$now)),
            'updatedAt'=>$now,
            'commit'=>(string)($extra['commit']??($previous['commit']??'')),
            'artifactId'=>(string)($extra['artifactId']??($previous['artifactId']??'')),
        ];
        foreach($extra as $k=>$v)$payload[$k]=$v;
        Files::writeJson($file,$payload);
        $requestId=(string)($payload['requestId']??'');
        if(preg_match('/^[a-f0-9]{32}$/',$requestId)){
            try{
                $patch=[
                    'state'=>$state,
                    'phase'=>$phase,
                    'progress'=>$payload['progress'],
                    'release'=>(string)($payload['release']??''),
                    'error'=>(string)($payload['error']??''),
                    'verificationSource'=>'local-release-manager',
                ];
                if($state==='deployed')$patch['completedAt']=date(DATE_ATOM);
                $this->jobs->patch($requestId,$patch);
            }catch(\Throwable){}
        }
    }

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
            $stateMeta=[
                'commit'=>(string)($meta['commit']??''),
                'artifactId'=>(string)($meta['artifactId']??''),
                'artifactDigest'=>(string)($meta['artifactDigest']??''),
                'downloadSha256'=>(string)($meta['downloadSha256']??''),
                'requestId'=>(string)($meta['requestId']??''),
            ];
            $this->writeDeploymentState($slug,'running','validating',38,$stateMeta+['startedAt'=>date(DATE_ATOM)]);
            $releaseId = date('Ymd-His') . '-' . substr((string)($meta['commit'] ?? bin2hex(random_bytes(4))), 0, 8);
            $stage = $runtime . '/staging/' . $releaseId;
            $releases = $runtime . '/releases';
            $releaseDir = $releases . '/' . $releaseId;
            $publicTarget = DIGIOPS_APP_HOME . '/' . $project['publicPath'];
            $privateTarget = DIGIOPS_APP_HOME . '/' . $project['privatePath'];
            Files::ensureDir($stage);
            Files::ensureDir($releases);

            $this->extractSafe($zipFile, $stage);
            [$publicPayload, $privatePayload] = $this->detectPayloads($stage);
            $this->validatePayload($publicPayload);
            $this->validatePrivatePayload($slug,$privatePayload);

            $this->writeDeploymentState($slug,'running','snapshotting',52,$stateMeta+['release'=>$releaseId]);
            if (is_dir($publicTarget) && $this->hasEntries($publicTarget)) {
                $backupId = 'pre-' . $releaseId;
                $backupDir = $releases . '/' . $backupId;
                Files::copyDir($publicTarget, $backupDir . '/public');
                Files::writeJson($backupDir . '/meta.json', [
                    'id'=>$backupId,
                    'type'=>'snapshot',
                    'createdAt'=>date(DATE_ATOM),
                    'source'=>'pre-deploy',
                ]);
            }

            $this->writeDeploymentState($slug,'running','staging-release',68,$stateMeta+['release'=>$releaseId]);
            if (is_dir($releaseDir)) Files::removeTree($releaseDir);
            Files::ensureDir($releaseDir);
            Files::copyDir($publicPayload, $releaseDir . '/public');
            if ($privatePayload !== null) Files::copyDir($privatePayload, $releaseDir . '/private');

            Files::writeJson($releaseDir . '/meta.json', [
                'id'=>$releaseId,
                'type'=>'release',
                'project'=>$slug,
                'commit'=>(string)($meta['commit'] ?? ''),
                'artifactId'=>(string)($meta['artifactId'] ?? ''),
                'requestId'=>(string)($meta['requestId'] ?? ''),
                'artifactDigest'=>(string)($meta['artifactDigest'] ?? ''),
                'createdAt'=>date(DATE_ATOM),
                'sha256'=>(string)($meta['downloadSha256'] ?? hash_file('sha256', $zipFile)),
                'splitPrivate'=>$privatePayload !== null,
            ]);

            $this->writeDeploymentState($slug,'running','publishing',82,$stateMeta+['release'=>$releaseId]);
            $publishTmp = dirname($publicTarget) . '/.' . $slug . '.publish-' . bin2hex(random_bytes(4));
            Files::copyDir($releaseDir . '/public', $publishTmp);

            $overlayPlan=null;
            if ($privatePayload !== null) {
                // Private code is overlaid because runtime state may coexist under
                // private_html/<app>/. Preflight every touched path and keep backups
                // until the public atomic switch succeeds, so a publication failure
                // cannot leave a partially updated private code tree behind.
                $overlayPlan=Files::beginOverlay(
                    $releaseDir . '/private',
                    $privateTarget,
                    $runtime . '/overlay-backup/' . $releaseId
                );
                Files::applyOverlay($overlayPlan);
            }

            try {
                $this->writeDeploymentState($slug,'running','switching',94,$stateMeta+['release'=>$releaseId]);
                $this->switcher->switch($publishTmp, $publicTarget, $slug);
            } catch (\Throwable $e) {
                if($overlayPlan!==null){
                    try { Files::rollbackOverlay($overlayPlan); }
                    catch(\Throwable $restore){
                        throw new RuntimeException('PUBLICATION_FAILED_PRIVATE_RESTORE_FAILED:'.$e->getMessage().':'.$restore->getMessage(),0,$e);
                    }
                }
                throw $e;
            }
            if($overlayPlan!==null) Files::commitOverlay($overlayPlan);

            $this->writeDeploymentState($slug,'running','activating-runtime',97,$stateMeta+[
                'release'=>$releaseId,
                'publication'=>'success',
                'runtimeActivation'=>'running',
            ]);
            $activation=$this->activateRuntimeIfPresent(
                $privateTarget,
                (string)($meta['commit']??'')
            );
            $this->writeDeploymentState($slug,'running','certifying-runtime',99,$stateMeta+[
                'release'=>$releaseId,
                'publication'=>'success',
                'runtimeActivation'=>$activation['required']?'success':'not-required',
                'runtimeCertification'=>$activation['required']?'success':'not-required',
                'activation'=>$activation,
            ]);

            $this->projects->patchRuntime($slug, [
                'status'=>'deployed',
                'health'=>'pending',
                'commit'=>(string)($meta['commit'] ?? '—'),
                'release'=>$releaseId,
                'lastDeploy'=>date(DATE_ATOM),
                'update'=>false,
            ]);
            $this->writeDeploymentState($slug,'deployed','complete',100,$stateMeta+[
                'release'=>$releaseId,
                'lastDeploy'=>date(DATE_ATOM),
                'publication'=>'success',
                'runtimeActivation'=>$activation['required']?'success':'not-required',
                'runtimeCertification'=>$activation['required']?'success':'not-required',
                'activation'=>$activation,
            ]);
            $this->prune($slug, (int)$project['retention']);
            $this->audit->write('DEPLOY_SUCCESS', [
                'project'=>$slug,
                'release'=>$releaseId,
                'commit'=>$meta['commit'] ?? null,
                'splitPrivate'=>$privatePayload !== null,
                'artifactId'=>$meta['artifactId'] ?? null,
                'artifactDigest'=>$meta['artifactDigest'] ?? null,
                'requestId'=>$meta['requestId'] ?? null,
            ], $user);
            return [
                'ok'=>true,
                'release'=>$releaseId,
                'requestId'=>(string)($meta['requestId']??''),
                'publicPath'=>$project['publicPath'],
                'privatePath'=>$privatePayload !== null ? $project['privatePath'] : null,
            ];
        } catch (\Throwable $e) {
            $currentState=$this->deploymentState($slug);
            $failurePhase=(string)($currentState['phase']??'failed');
            $this->writeDeploymentState($slug,'failed',$failurePhase!==''?$failurePhase:'failed',100,[
                'commit'=>(string)($meta['commit']??''),
                'artifactId'=>(string)($meta['artifactId']??''),
                'requestId'=>(string)($meta['requestId']??''),
                'publication'=>(string)($currentState['publication']??''),
                'runtimeActivation'=>str_starts_with($failurePhase,'activating')||str_starts_with($failurePhase,'certifying')
                    ? 'failed'
                    : (string)($currentState['runtimeActivation']??''),
                'runtimeCertification'=>(string)($currentState['runtimeCertification']??''),
                'error'=>$e->getMessage(),
            ]);
            throw $e;
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

        $runtime = DIGIOPS_PRIVATE_ROOT . '/projects/' . $slug;
        Files::ensureDir($runtime);
        $lock=fopen($runtime.'/deploy.lock','c+');
        if(!$lock || !flock($lock,LOCK_EX|LOCK_NB)) throw new RuntimeException('DEPLOYMENT_LOCKED');

        try {
        $releaseRoot = $runtime . '/releases/' . $releaseId;
        $publicSource = is_dir($releaseRoot . '/public') ? $releaseRoot . '/public' : $releaseRoot . '/payload';
        $privateSource = is_dir($releaseRoot . '/private') ? $releaseRoot . '/private' : null;
        if (!is_dir($publicSource)) throw new RuntimeException('RELEASE_NOT_FOUND');
        $this->validatePrivatePayload($slug,$privateSource);

        $publicTarget = DIGIOPS_APP_HOME . '/' . $project['publicPath'];
        $privateTarget = DIGIOPS_APP_HOME . '/' . $project['privatePath'];
        $tmp = dirname($publicTarget) . '/.' . $slug . '.rollback-' . bin2hex(random_bytes(4));
        Files::copyDir($publicSource, $tmp);

        if ($privateSource !== null) {
            Files::ensureDir($privateTarget);
            Files::copyDir($privateSource, $privateTarget);
        }

        $this->switcher->switch($tmp, $publicTarget, $slug);

        $meta = Files::readJson($releaseRoot . '/meta.json', []);
        $this->projects->patchRuntime($slug, [
            'status'=>'deployed',
            'health'=>'pending',
            'release'=>$releaseId,
            'commit'=>(string)($meta['commit'] ?? '—'),
            'lastDeploy'=>date(DATE_ATOM),
        ]);
        $this->audit->write('ROLLBACK_SUCCESS', [
            'project'=>$slug,
            'release'=>$releaseId,
            'splitPrivate'=>$privateSource !== null,
        ], $user);

        return ['ok'=>true,'release'=>$releaseId];
        } finally {
            flock($lock,LOCK_UN);
            fclose($lock);
        }
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
            $publicDir = is_dir($path . '/public') ? $path . '/public' : $path . '/payload';
            $meta['size'] = Files::directorySize($publicDir)
                + (is_dir($path . '/private') ? Files::directorySize($path . '/private') : 0);
            $out[] = $meta;
        }

        usort($out, fn($a,$b)=>strcmp((string)($b['createdAt'] ?? ''),(string)($a['createdAt'] ?? '')));
        return $out;
    }

    private function activateRuntimeIfPresent(string $privateTarget,string $expectedCommit): array
    {
        $hook=rtrim($privateTarget,'/').'/scripts/digiops-runtime-activate.py';
        if(!is_file($hook)) return ['required'=>false,'status'=>'NOT_REQUIRED'];

        $descriptors=[
            0=>['pipe','r'],
            1=>['pipe','w'],
            2=>['pipe','w'],
        ];
        $proc=@proc_open(['python3',$hook,'--expected-commit',$expectedCommit],$descriptors,$pipes,$privateTarget);
        if(!is_resource($proc)) throw new RuntimeException('RUNTIME_ACTIVATION_START_FAILED');
        fclose($pipes[0]);
        stream_set_blocking($pipes[1],false);
        stream_set_blocking($pipes[2],false);
        $stdout='';$stderr='';$deadline=microtime(true)+150;$exitCode=null;
        try{
            while(true){
                $stdout.=(string)stream_get_contents($pipes[1]);
                $stderr.=(string)stream_get_contents($pipes[2]);
                $status=proc_get_status($proc);
                if(!($status['running']??false)){
                    $exitCode=(int)($status['exitcode']??-1);
                    break;
                }
                if(microtime(true)>=$deadline){
                    @proc_terminate($proc,15);
                    usleep(250000);
                    $status=proc_get_status($proc);
                    if($status['running']??false)@proc_terminate($proc,9);
                    throw new RuntimeException('RUNTIME_ACTIVATION_TIMEOUT');
                }
                usleep(100000);
            }
            $stdout.=(string)stream_get_contents($pipes[1]);
            $stderr.=(string)stream_get_contents($pipes[2]);
        } finally {
            fclose($pipes[1]);fclose($pipes[2]);
            $closed=proc_close($proc);
            if($exitCode===null && is_int($closed))$exitCode=$closed;
        }

        $lines=array_values(array_filter(array_map('trim',preg_split('/\R/',$stdout)?:[]),fn($v)=>$v!==''));
        $payload=[];
        if($lines){
            $decoded=json_decode((string)end($lines),true);
            if(is_array($decoded))$payload=$decoded;
        }
        if($exitCode!==0 || empty($payload['ok'])){
            $detail=trim($stderr!==''?$stderr:$stdout);
            if($detail==='')$detail='activation hook returned no certification';
            throw new RuntimeException('RUNTIME_ACTIVATION_FAILED:'.substr($detail,0,1800));
        }
        return ['required'=>true,'status'=>'SUCCESS']+$payload;
    }

    private function extractSafe(string $zipFile, string $target): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) throw new RuntimeException('INVALID_ZIP');

        for ($i=0; $i<$zip->numFiles; $i++) {
            $name = str_replace('\\','/',$zip->getNameIndex($i));
            if ($name === '' || str_starts_with($name,'/') || str_contains($name,'../') || preg_match('#^[A-Za-z]:/#',$name)) {
                $zip->close();
                throw new RuntimeException('ZIP_PATH_TRAVERSAL');
            }
        }

        if (!$zip->extractTo($target)) {
            $zip->close();
            throw new RuntimeException('ZIP_EXTRACT_FAILED');
        }
        $zip->close();

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($target, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $item) if ($item->isLink()) throw new RuntimeException('SYMLINK_NOT_ALLOWED');
    }

    private function detectPayloads(string $stage): array
    {
        $root = $stage;
        $entries = array_values(array_filter(
            array_diff(scandir($stage) ?: [], ['.','..']),
            fn($x)=>$x !== '__MACOSX'
        ));

        if (count($entries) === 1 && is_dir($stage . '/' . $entries[0])) {
            $candidate = $stage . '/' . $entries[0];
            if (
                is_dir($candidate . '/public')
                || is_dir($candidate . '/dist')
                || is_file($candidate . '/index.html')
                || is_file($candidate . '/index.php')
            ) {
                $root = $candidate;
            }
        }

        if (is_dir($root . '/public')) {
            return [$root . '/public', is_dir($root . '/private') ? $root . '/private' : null];
        }
        if (is_dir($root . '/dist')) return [$root . '/dist', null];
        return [$root, null];
    }

    private function validatePrivatePayload(string $slug, ?string $payload): void
    {
        if($payload===null || $slug!=='digiops') return;
        $allowed=['app','agent','build'];
        foreach(array_diff(scandir($payload) ?: [], ['.','..']) as $name){
            if(!in_array($name,$allowed,true)) throw new RuntimeException('DIGIOPS_PRIVATE_PAYLOAD_UNMANAGED_'.$name);
        }
    }

    private function validatePayload(string $payload): void
    {
        if (!is_dir($payload)) throw new RuntimeException('PAYLOAD_MISSING');
        if (!is_file($payload . '/index.html') && !is_file($payload . '/index.php')) {
            throw new RuntimeException('ENTRYPOINT_MISSING');
        }
        if (Files::directorySize($payload) > 1024 * 1024 * 1024) {
            throw new RuntimeException('PAYLOAD_TOO_LARGE');
        }
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
