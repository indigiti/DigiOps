<?php
declare(strict_types=1);
@set_time_limit(600);
@ignore_user_abort(true);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
const AGENT_VERSION='1.6.1';
const MAX_SKEW=300;
const MAX_CHUNK=786432;
function fail(string $error,int $status=400): never {
    $active=$GLOBALS['DIGIOPS_ACTIVE_DEPLOYMENT']??null;
    if(is_array($active) && empty($GLOBALS['DIGIOPS_MARKING_DEPLOY_FAILURE']) && function_exists('setDeploymentState')){
        $GLOBALS['DIGIOPS_MARKING_DEPLOY_FAILURE']=true;
        try{
            $slug=(string)($active['slug']??'');
            if($slug!==''){
                $current=deploymentState($slug);
                $phase=(string)($current['phase']??'failed');
                setDeploymentState($slug,'failed',$phase!==''?$phase:'failed',100,[
                    'commit'=>(string)($active['commit']??''),
                    'artifactId'=>(string)($active['artifactId']??''),
                    'requestId'=>(string)($active['requestId']??''),
                    'uploadId'=>(string)($active['uploadId']??''),
                    'publication'=>(string)($current['publication']??''),
                    'runtimeActivation'=>str_starts_with($phase,'activating')||str_starts_with($phase,'certifying')
                        ? 'failed'
                        : (string)($current['runtimeActivation']??''),
                    'runtimeCertification'=>(string)($current['runtimeCertification']??''),
                    'error'=>$error,
                ]);
            }
        }catch(Throwable){}
        $GLOBALS['DIGIOPS_MARKING_DEPLOY_FAILURE']=false;
    }
    http_response_code($status); echo json_encode(['ok'=>false,'error'=>$error],JSON_UNESCAPED_SLASHES); exit;
}
function ok(array $payload=[]): never { echo json_encode(['ok'=>true]+$payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function appHome(): string { $env=trim((string)getenv('DIGIOPS_AGENT_HOME')); if($env!=='')return rtrim($env,'/'); $doc=realpath((string)($_SERVER['DOCUMENT_ROOT']??''))?:''; if($doc==='')fail('AGENT_HOME_UNAVAILABLE',500); return dirname($doc); }
function runtimeRoot(): string { return appHome().'/private_html/digiops-agent'; }
function ensureDir(string $dir,int $mode=0750): void { if(is_dir($dir))return; if(!mkdir($dir,$mode,true)&&!is_dir($dir))fail('DIRECTORY_CREATE_FAILED',500); }
function removeTree(string $path): void { if(!file_exists($path)&&!is_link($path))return; if(is_file($path)||is_link($path)){@unlink($path);return;} foreach(array_diff(scandir($path)?:[],['.','..']) as $n)removeTree($path.'/'.$n); @rmdir($path); }
function copyFileAtomic(string $src,string $dst): void {
    if(is_link($src))fail('SYMLINK_NOT_ALLOWED');
    ensureDir(dirname($dst));
    if(is_link($dst))fail('TARGET_SYMLINK_NOT_ALLOWED');
    $tmp=dirname($dst).'/.'.basename($dst).'.digiops-'.bin2hex(random_bytes(4));
    if(!@copy($src,$tmp)){@unlink($tmp);fail('COPY_FAILED',500);}
    $mode=@fileperms($src);if(is_int($mode))@chmod($tmp,$mode&0777);
    if(!@rename($tmp,$dst)){@unlink($tmp);fail('ATOMIC_REPLACE_FAILED',500);}
}
function copyDir(string $src,string $dst): void {
    ensureDir($dst);
    foreach(array_diff(scandir($src)?:[],['.','..']) as $n){
        $s=$src.'/'.$n;$d=$dst.'/'.$n;
        if(is_link($s))fail('SYMLINK_NOT_ALLOWED');
        if(is_dir($s))copyDir($s,$d);
        else copyFileAtomic($s,$d);
    }
}
function atomicSwitchDir(string $prepared,string $target,string $label): void {
    if(!is_dir($prepared))fail('PREPARED_RELEASE_MISSING',500);
    $parent=dirname($target);ensureDir($parent);
    $previous=$parent.'/.'.$label.'.previous-'.bin2hex(random_bytes(6));
    $hadCurrent=is_dir($target);
    if($hadCurrent && !@rename($target,$previous))fail('CURRENT_RELEASE_PARK_FAILED',500);
    if(@rename($prepared,$target)){if($hadCurrent&&is_dir($previous))removeTree($previous);return;}
    $restored=!$hadCurrent;
    if($hadCurrent&&is_dir($previous)&&!file_exists($target))$restored=@rename($previous,$target);
    if(!$restored)fail('PUBLISH_RENAME_FAILED_RESTORE_FAILED',500);
    fail('PUBLISH_RENAME_FAILED_RESTORED',500);
}
function dirSize(string $dir): int { if(!is_dir($dir))return 0;$sum=0;$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS));foreach($it as $f)if($f->isFile()&&!$f->isLink())$sum+=$f->getSize();return $sum; }
function safeSlug(string $value): string { $v=strtolower(trim($value)); if(!preg_match('/^[a-z0-9][a-z0-9._-]{1,79}$/',$v))fail('INVALID_PROJECT_SLUG'); return $v; }
function safeManagedRelative(string $path,string $root): string { $p=trim(str_replace('\\','/',$path),'/'); if($p===''||str_contains($p,'..')||!preg_match('#^[A-Za-z0-9._/-]+$#',$p))fail('INVALID_RELATIVE_PATH'); if($p!==$root&&!str_starts_with($p,$root.'/'))fail('UNMANAGED_TARGET_PATH'); return $p; }
function projectPaths(array $payload): array { $slug=safeSlug((string)($payload['project']??'')); $public=safeManagedRelative((string)($payload['publicPath']??('public_html/'.$slug)),'public_html'); $private=safeManagedRelative((string)($payload['privatePath']??('private_html/'.$slug)),'private_html'); return [$slug,appHome().'/'.$public,appHome().'/'.$private]; }
function verifySignature(string $body): void {
    $secret=(string)getenv('DIGIOPS_AGENT_SECRET'); if(strlen($secret)<32)fail('AGENT_SECRET_NOT_CONFIGURED',503);
    $ts=(string)($_SERVER['HTTP_X_DIGIOPS_TIMESTAMP']??''); $nonce=(string)($_SERVER['HTTP_X_DIGIOPS_NONCE']??''); $sig=strtolower((string)($_SERVER['HTTP_X_DIGIOPS_SIGNATURE']??''));
    if(!ctype_digit($ts)||abs(time()-(int)$ts)>MAX_SKEW)fail('SIGNATURE_TIMESTAMP_INVALID',401);
    if(!preg_match('/^[a-f0-9]{32}$/',$nonce)||!preg_match('/^[a-f0-9]{64}$/',$sig))fail('SIGNATURE_HEADERS_INVALID',401);
    $expected=hash_hmac('sha256',$ts."\n".$nonce."\n".$body,$secret); if(!hash_equals($expected,$sig))fail('SIGNATURE_INVALID',401);
    $dir=runtimeRoot().'/nonces';ensureDir($dir,0700);
    foreach(array_diff(scandir($dir)?:[],['.','..']) as $n){$p=$dir.'/'.$n;if(is_file($p)&&filemtime($p)<time()-MAX_SKEW)@unlink($p);}
    $file=$dir.'/'.$nonce;$fp=@fopen($file,'x');if(!$fp)fail('NONCE_REPLAY',409);fwrite($fp,$ts);fclose($fp);@chmod($file,0600);
}
function readJsonFile(string $file,array $default=[]): array { if(!is_file($file))return $default;$d=json_decode((string)file_get_contents($file),true);return is_array($d)?$d:$default; }
function writeJsonFile(string $file,array $data): void { ensureDir(dirname($file),0700);$tmp=$file.'.tmp-'.bin2hex(random_bytes(4));file_put_contents($tmp,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL,LOCK_EX);@chmod($tmp,0600);if(!rename($tmp,$file))fail('JSON_PUBLISH_FAILED',500); }
function extractSafe(string $zipFile,string $target): void { if(!class_exists('ZipArchive'))fail('ZIP_EXTENSION_UNAVAILABLE',500);$zip=new ZipArchive();if($zip->open($zipFile)!==true)fail('INVALID_ZIP');for($i=0;$i<$zip->numFiles;$i++){$name=str_replace('\\','/',$zip->getNameIndex($i));if($name===''||str_starts_with($name,'/')||str_contains($name,'../')||preg_match('#^[A-Za-z]:/#',$name)){$zip->close();fail('ZIP_PATH_TRAVERSAL');}}ensureDir($target);if(!$zip->extractTo($target)){$zip->close();fail('ZIP_EXTRACT_FAILED',500);} $zip->close(); }
function payloads(string $stage): array { $root=$stage;$entries=array_values(array_filter(array_diff(scandir($stage)?:[],['.','..']),fn($x)=>$x!=='__MACOSX'));if(count($entries)===1&&is_dir($stage.'/'.$entries[0])){$candidate=$stage.'/'.$entries[0];if(is_dir($candidate.'/public')||is_dir($candidate.'/dist')||is_file($candidate.'/index.html')||is_file($candidate.'/index.php'))$root=$candidate;}if(is_dir($root.'/public'))return[$root.'/public',is_dir($root.'/private')?$root.'/private':null];if(is_dir($root.'/dist'))return[$root.'/dist',null];return[$root,null]; }
function validatePrivatePayload(string $slug,?string $payload): void { if($payload===null||$slug!=='digiops')return;$allowed=['app','agent','build'];foreach(array_diff(scandir($payload)?:[],['.','..']) as $name)if(!in_array($name,$allowed,true))fail('DIGIOPS_PRIVATE_PAYLOAD_UNMANAGED_'.$name); }
function projectRuntime(string $slug): string { return runtimeRoot().'/projects/'.$slug; }
function deploymentStateFile(string $slug): string { return projectRuntime($slug).'/deployment.json'; }
function deploymentState(string $slug): array { return readJsonFile(deploymentStateFile($slug),[]); }
function setDeploymentState(string $slug,string $state,string $phase,int $progress,array $extra=[]): void {
    $previous=deploymentState($slug);
    $now=date(DATE_ATOM);
    $payload=[
        'state'=>$state,
        'phase'=>$phase,
        'progress'=>max(0,min(100,$progress)),
        'startedAt'=>(string)($previous['startedAt']??$now),
        'updatedAt'=>$now,
        'commit'=>(string)($extra['commit']??($previous['commit']??'')),
        'artifactId'=>(string)($extra['artifactId']??($previous['artifactId']??'')),
        'artifactDigest'=>(string)($extra['artifactDigest']??($previous['artifactDigest']??'')),
        'downloadSha256'=>(string)($extra['downloadSha256']??($previous['downloadSha256']??'')),
        'requestId'=>(string)($extra['requestId']??($previous['requestId']??'')),
        'uploadId'=>(string)($extra['uploadId']??($previous['uploadId']??'')),
    ];
    foreach($extra as $k=>$v){$payload[$k]=$v;}
    writeJsonFile(deploymentStateFile($slug),$payload);
}
function deploymentStateIsActive(array $state): bool {
    if(!in_array((string)($state['state']??''),['uploading','running'],true))return false;
    $updated=strtotime((string)($state['updatedAt']??''));
    return is_int($updated) && $updated >= time()-900;
}
function listReleases(string $slug): array { $dir=projectRuntime($slug).'/releases';if(!is_dir($dir))return[];$out=[];foreach(array_diff(scandir($dir)?:[],['.','..']) as $name){$path=$dir.'/'.$name;if(!is_dir($path))continue;$meta=readJsonFile($path.'/meta.json',['id'=>$name]);$meta['size']=dirSize($path.'/public')+(is_dir($path.'/private')?dirSize($path.'/private'):0);$out[]=$meta;}usort($out,fn($a,$b)=>strcmp((string)($b['createdAt']??''),(string)($a['createdAt']??'')));return$out; }
function commandPath(string $name): ?string {
    if(!preg_match('/^[A-Za-z0-9._-]+$/',$name))return null;
    $path=(string)(getenv('PATH')?:'/usr/local/bin:/usr/bin:/bin');
    foreach(array_filter(explode(PATH_SEPARATOR,$path),'strlen') as $dir){$candidate=rtrim($dir,'/').'/'.$name;if(is_file($candidate)&&is_executable($candidate))return$candidate;}
    foreach(['/usr/local/bin','/usr/bin','/bin'] as $dir){$candidate=$dir.'/'.$name;if(is_file($candidate)&&is_executable($candidate))return$candidate;}
    return null;
}
function runtimeActivationCapability(): array {
    $python=commandPath('python3');$timeout=commandPath('timeout');
    $proc=function_exists('proc_open')&&function_exists('proc_get_status')&&function_exists('proc_terminate')&&function_exists('proc_close');
    $exec=function_exists('exec');
    $executor=$proc?'proc_open':(($exec&&$timeout!==null)?'exec':'');
    $reason='';
    if($python===null)$reason='RUNTIME_ACTIVATION_PYTHON3_UNAVAILABLE';
    elseif($executor==='')$reason=(!$proc&&$exec&&$timeout===null)?'RUNTIME_ACTIVATION_TIMEOUT_TOOL_UNAVAILABLE':'RUNTIME_ACTIVATION_EXECUTOR_UNAVAILABLE';
    return['available'=>$reason==='','executor'=>$reason===''?$executor:'','python'=>$python,'timeout'=>$timeout,'proc_open_available'=>$proc,'exec_available'=>$exec,'reason'=>$reason];
}
function assertRuntimeActivationCapability(?string $privatePayload): void {
    if($privatePayload===null)return;
    $hook=rtrim($privatePayload,'/').'/scripts/digiops-runtime-activate.py';
    if(!is_file($hook))return;
    $cap=runtimeActivationCapability();if(empty($cap['available']))throw new RuntimeException((string)$cap['reason']);
}
function certifyActivationOutput(int $exit,string $stdout,string $stderr=''): array {
    $lines=array_values(array_filter(array_map('trim',preg_split('/\R/',$stdout)?:[]),fn($v)=>$v!==''));
    $result=[];if($lines){$decoded=json_decode((string)end($lines),true);if(is_array($decoded))$result=$decoded;}
    if($exit!==0||empty($result['ok'])){$detail=trim($stderr!==''?$stderr:$stdout);if($detail==='')$detail='activation hook returned no certification';throw new RuntimeException('RUNTIME_ACTIVATION_FAILED:'.substr($detail,0,1800));}
    return$result;
}
function activateRuntimeIfPresent(string $privateTarget,string $expectedCommit): array {
    $hook=rtrim($privateTarget,'/').'/scripts/digiops-runtime-activate.py';
    if(!is_file($hook))return['required'=>false,'status'=>'NOT_REQUIRED'];
    $expectedCommit=strtolower(trim($expectedCommit));if(!preg_match('/^[a-f0-9]{40}$/',$expectedCommit))throw new RuntimeException('RUNTIME_ACTIVATION_EXPECTED_COMMIT_INVALID');
    $cap=runtimeActivationCapability();if(empty($cap['available']))throw new RuntimeException((string)$cap['reason']);
    $executor=(string)$cap['executor'];$python=(string)$cap['python'];
    if($executor==='proc_open'){
        $spec=[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
        $proc=@proc_open([$python,$hook,'--expected-commit',$expectedCommit],$spec,$pipes,$privateTarget);
        if(!is_resource($proc))throw new RuntimeException('RUNTIME_ACTIVATION_START_FAILED');
        fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
        $stdout='';$stderr='';$deadline=microtime(true)+150;$exit=null;
        try{
            while(true){
                $stdout.=(string)stream_get_contents($pipes[1]);$stderr.=(string)stream_get_contents($pipes[2]);$status=proc_get_status($proc);
                if(!($status['running']??false)){$exit=(int)($status['exitcode']??-1);break;}
                if(microtime(true)>=$deadline){@proc_terminate($proc,15);usleep(250000);$status=proc_get_status($proc);if($status['running']??false)@proc_terminate($proc,9);throw new RuntimeException('RUNTIME_ACTIVATION_TIMEOUT');}
                usleep(100000);
            }
            $stdout.=(string)stream_get_contents($pipes[1]);$stderr.=(string)stream_get_contents($pipes[2]);
        }finally{fclose($pipes[1]);fclose($pipes[2]);$closed=proc_close($proc);if($exit===null&&is_int($closed))$exit=$closed;}
        $result=certifyActivationOutput((int)($exit??-1),$stdout,$stderr);
    }else{
        $cmd='cd '.escapeshellarg($privateTarget).' && '.escapeshellarg((string)$cap['timeout']).' --signal=TERM --kill-after=2s 150s '.escapeshellarg($python).' '.escapeshellarg($hook).' --expected-commit '.escapeshellarg($expectedCommit).' 2>&1';
        $lines=[];$exit=-1;@exec($cmd,$lines,$exit);if($exit===124||$exit===137)throw new RuntimeException('RUNTIME_ACTIVATION_TIMEOUT');
        $result=certifyActivationOutput($exit,implode(PHP_EOL,$lines));
    }
    return['required'=>true,'status'=>'SUCCESS','executor'=>$executor]+$result;
}
function publishRelease(array $payload,string $zipFile): array {
    [$slug,$publicTarget,$privateTarget]=projectPaths($payload);$runtime=projectRuntime($slug);ensureDir($runtime,0700);$lock=fopen($runtime.'/deploy.lock','c+');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))fail('DEPLOYMENT_LOCKED',409);
    $stateMeta=['commit'=>(string)($payload['commit']??''),'artifactId'=>(string)($payload['artifactId']??''),'artifactDigest'=>(string)($payload['artifactDigest']??''),'downloadSha256'=>(string)($payload['downloadSha256']??''),'requestId'=>(string)($payload['requestId']??''),'uploadId'=>(string)($payload['uploadId']??'')];
    try{
        setDeploymentState($slug,'running','validating',38,$stateMeta);
        $releaseId=date('Ymd-His').'-'.substr((string)($payload['commit']??bin2hex(random_bytes(4))),0,8);$stage=$runtime.'/staging/'.$releaseId;$releaseDir=$runtime.'/releases/'.$releaseId;ensureDir($stage);ensureDir(dirname($releaseDir));extractSafe($zipFile,$stage);[$publicPayload,$privatePayload]=payloads($stage);validatePrivatePayload($slug,$privatePayload);
        if(!is_file($publicPayload.'/index.html')&&!is_file($publicPayload.'/index.php'))fail('ENTRYPOINT_MISSING');if(dirSize($publicPayload)>1024*1024*1024)fail('PAYLOAD_TOO_LARGE');
        assertRuntimeActivationCapability($privatePayload);
        setDeploymentState($slug,'running','snapshotting',52,$stateMeta+['release'=>$releaseId]);
        if(is_dir($publicTarget)&&count(array_diff(scandir($publicTarget)?:[],['.','..']))){$backup='pre-'.$releaseId;copyDir($publicTarget,$runtime.'/releases/'.$backup.'/public');writeJsonFile($runtime.'/releases/'.$backup.'/meta.json',['id'=>$backup,'type'=>'snapshot','createdAt'=>date(DATE_ATOM),'source'=>'pre-deploy']);}
        setDeploymentState($slug,'running','staging-release',68,$stateMeta+['release'=>$releaseId]);
        if(is_dir($releaseDir))removeTree($releaseDir);ensureDir($releaseDir);copyDir($publicPayload,$releaseDir.'/public');if($privatePayload!==null)copyDir($privatePayload,$releaseDir.'/private');
        writeJsonFile($releaseDir.'/meta.json',['id'=>$releaseId,'type'=>'release','project'=>$slug,'commit'=>(string)($payload['commit']??''),'artifactId'=>(string)($payload['artifactId']??''),'requestId'=>(string)($payload['requestId']??''),'artifactDigest'=>(string)($payload['artifactDigest']??''),'createdAt'=>date(DATE_ATOM),'sha256'=>(string)($payload['downloadSha256']??hash_file('sha256',$zipFile)),'splitPrivate'=>$privatePayload!==null]);
        setDeploymentState($slug,'running','publishing',82,$stateMeta+['release'=>$releaseId]);
        $tmp=dirname($publicTarget).'/.'.$slug.'.publish-'.bin2hex(random_bytes(4));copyDir($releaseDir.'/public',$tmp);
        setDeploymentState($slug,'running','switching',94,$stateMeta+['release'=>$releaseId]);
        if($privatePayload!==null){ensureDir($privateTarget);copyDir($releaseDir.'/private',$privateTarget);}atomicSwitchDir($tmp,$publicTarget,$slug);
        setDeploymentState($slug,'running','activating-runtime',97,$stateMeta+['release'=>$releaseId,'publication'=>'success','runtimeActivation'=>'running']);
        $activation=activateRuntimeIfPresent($privateTarget,(string)($payload['commit']??''));
        setDeploymentState($slug,'running','certifying-runtime',99,$stateMeta+[
            'release'=>$releaseId,
            'publication'=>'success',
            'runtimeActivation'=>$activation['required']?'success':'not-required',
            'runtimeCertification'=>$activation['required']?'success':'not-required',
            'activation'=>$activation,
        ]);
        $lastDeploy=date(DATE_ATOM);
        writeJsonFile($runtime.'/current.json',['release'=>$releaseId,'commit'=>(string)($payload['commit']??''),'requestId'=>(string)($payload['requestId']??''),'lastDeploy'=>$lastDeploy]);
        setDeploymentState($slug,'deployed','complete',100,$stateMeta+[
            'release'=>$releaseId,'lastDeploy'=>$lastDeploy,'publication'=>'success',
            'runtimeActivation'=>$activation['required']?'success':'not-required',
            'runtimeCertification'=>$activation['required']?'success':'not-required',
            'activation'=>$activation,
        ]);
        removeTree($stage);return['release'=>$releaseId,'activation'=>$activation];
    } finally { flock($lock,LOCK_UN);fclose($lock); }
}
$raw=(string)file_get_contents('php://input');verifySignature($raw);$data=json_decode($raw,true);if(!is_array($data))fail('INVALID_JSON');$action=(string)($data['action']??'');$payload=is_array($data['payload']??null)?$data['payload']:[];
try{
    if($action==='ping')ok(['agentVersion'=>AGENT_VERSION,'capabilities'=>['health','releases','deployment-status','deployment-request-id','atomic-switch-v1','runtime-activation-v1','runtime-activation-v2','files','deploy-chunked','rollback'],'runtime'=>['php'=>PHP_VERSION,'curl'=>extension_loaded('curl'),'zip'=>extension_loaded('zip'),'runtimeActivation'=>runtimeActivationCapability()],'server'=>php_uname('n')]);
    if($action==='health'){[$slug,$public,$private]=projectPaths($payload);$url=trim((string)($payload['url']??''));$healthPath=(string)($payload['healthPath']??'/');$http=['ok'=>false,'status'=>null,'ms'=>null];if($url!==''&&function_exists('curl_init')){$parts=parse_url($url);if(!filter_var($url,FILTER_VALIDATE_URL)||!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'||empty($parts['host'])||isset($parts['user'])||isset($parts['pass']))fail('INVALID_HEALTH_URL');$probe=rtrim($url,'/').'/'.ltrim($healthPath,'/');$start=microtime(true);$ch=curl_init($probe);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_NOBODY=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_TIMEOUT=>8,CURLOPT_CONNECTTIMEOUT=>4]);curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);$http=['ok'=>$status>=200&&$status<400,'status'=>$status,'ms'=>(int)((microtime(true)-$start)*1000)];}$storage=['exists'=>is_dir($public),'bytes'=>dirSize($public),'writable'=>is_dir(dirname($public))&&is_writable(dirname($public))];$runtime=['php'=>PHP_VERSION,'curl'=>extension_loaded('curl'),'zip'=>extension_loaded('zip'),'sodium'=>extension_loaded('sodium')];ok(['url'=>$probe,'http'=>$http,'storage'=>$storage,'runtime'=>$runtime,'checkedAt'=>date(DATE_ATOM)]);}
    if($action==='releases'){$slug=safeSlug((string)($payload['project']??''));ok(['releases'=>listReleases($slug)]);}
    if($action==='deployment-status'){$slug=safeSlug((string)($payload['project']??''));$current=readJsonFile(projectRuntime($slug).'/current.json',[]);$deployment=deploymentState($slug);ok(['current'=>$current,'deployment'=>$deployment]);}
    if($action==='files'){[$slug,$public,$private]=projectPaths($payload);$scope=(string)($payload['scope']??'public');if(!in_array($scope,['public','private'],true))fail('INVALID_SCOPE');$root=$scope==='private'?$private:$public;$relative=trim(str_replace('\\','/',(string)($payload['path']??'')),'/');if(str_contains($relative,'..')||($relative!==''&&!preg_match('#^[A-Za-z0-9._/-]+$#',$relative)))fail('INVALID_PATH');$path=$relative===''?$root:$root.'/'.$relative;$rootReal=realpath($root)?:$root;$resolved=realpath($path);if($resolved!==false&&$resolved!==$rootReal&&!str_starts_with($resolved,$rootReal.'/'))fail('PATH_ESCAPE');$items=[];if(is_dir($path)){foreach(array_diff(scandir($path)?:[],['.','..']) as $name){if(str_starts_with($name,'.'))continue;$full=$path.'/'.$name;$items[]=['name'=>$name,'type'=>is_dir($full)?'dir':'file','size'=>is_file($full)?filesize($full):null,'modified'=>date(DATE_ATOM,filemtime($full)?:time())];}}ok(['listing'=>['path'=>$relative,'items'=>$items]]);}
    if($action==='deploy-start'){$slug=safeSlug((string)($payload['project']??''));$size=(int)($payload['size']??0);if($size<1||$size>1024*1024*1024)fail('ARTIFACT_SIZE_INVALID');$existing=deploymentState($slug);if(deploymentStateIsActive($existing))fail('DEPLOYMENT_ALREADY_RUNNING',409);$id=bin2hex(random_bytes(12));$dir=projectRuntime($slug).'/incoming/'.$id;ensureDir($dir,0700);file_put_contents($dir.'/artifact.zip','');$meta=(array)($payload['meta']??[]);writeJsonFile($dir.'/meta.json',['project'=>$slug,'size'=>$size,'received'=>0,'sha256'=>(string)($payload['sha256']??''),'meta'=>$meta]);setDeploymentState($slug,'uploading','uploading',8,['commit'=>(string)($meta['commit']??''),'artifactId'=>(string)($meta['artifactId']??''),'artifactDigest'=>(string)($meta['artifactDigest']??''),'downloadSha256'=>(string)($meta['downloadSha256']??''),'requestId'=>(string)($meta['requestId']??''),'uploadId'=>$id,'received'=>0,'size'=>$size,'startedAt'=>date(DATE_ATOM)]);ok(['uploadId'=>$id,'chunkBytes'=>524288]);}
    if($action==='deploy-chunk'){$slug=safeSlug((string)($payload['project']??''));$id=(string)($payload['uploadId']??'');if(!preg_match('/^[a-f0-9]{24}$/',$id))fail('UPLOAD_ID_INVALID');$dir=projectRuntime($slug).'/incoming/'.$id;$meta=readJsonFile($dir.'/meta.json',[]);if(!$meta)fail('UPLOAD_NOT_FOUND',404);$offset=(int)($payload['offset']??-1);if($offset!==(int)($meta['received']??0))fail('UPLOAD_OFFSET_MISMATCH',409);$chunk=base64_decode((string)($payload['data']??''),true);if($chunk===false||$chunk==='')fail('CHUNK_INVALID');if(strlen($chunk)>MAX_CHUNK)fail('CHUNK_TOO_LARGE');$fp=fopen($dir.'/artifact.zip','ab');if(!$fp)fail('UPLOAD_WRITE_FAILED',500);$written=fwrite($fp,$chunk);fclose($fp);if($written!==strlen($chunk))fail('UPLOAD_WRITE_FAILED',500);$meta['received']=$offset+$written;writeJsonFile($dir.'/meta.json',$meta);$size=max(1,(int)($meta['size']??1));$pct=8+(int)floor(22*min(1,$meta['received']/$size));setDeploymentState($slug,'uploading','uploading',$pct,['uploadId'=>$id,'received'=>$meta['received'],'size'=>$size]);ok(['received'=>$meta['received']]);}
    if($action==='deploy-commit'){$slug=safeSlug((string)($payload['project']??''));$id=(string)($payload['uploadId']??'');if(!preg_match('/^[a-f0-9]{24}$/',$id))fail('UPLOAD_ID_INVALID');$dir=projectRuntime($slug).'/incoming/'.$id;$upload=readJsonFile($dir.'/meta.json',[]);if(!$upload)fail('UPLOAD_NOT_FOUND',404);$zip=$dir.'/artifact.zip';if((int)($upload['received']??0)!==(int)($upload['size']??-1)||filesize($zip)!==(int)$upload['size'])fail('UPLOAD_INCOMPLETE');$expected=strtolower((string)($upload['sha256']??''));if($expected!==''&&!hash_equals($expected,strtolower(hash_file('sha256',$zip))))fail('ARTIFACT_HASH_MISMATCH');$meta=(array)($upload['meta']??[]);$meta['project']=$slug;$meta['uploadId']=$id;$meta['publicPath']=$payload['publicPath']??('public_html/'.$slug);$meta['privatePath']=$payload['privatePath']??('private_html/'.$slug);$GLOBALS['DIGIOPS_ACTIVE_DEPLOYMENT']=['slug'=>$slug,'commit'=>(string)($meta['commit']??''),'artifactId'=>(string)($meta['artifactId']??''),'artifactDigest'=>(string)($meta['artifactDigest']??''),'downloadSha256'=>(string)($meta['downloadSha256']??''),'requestId'=>(string)($meta['requestId']??''),'uploadId'=>$id];try{$result=publishRelease($meta,$zip);$GLOBALS['DIGIOPS_ACTIVE_DEPLOYMENT']=null;removeTree($dir);ok($result);}catch(Throwable $e){setDeploymentState($slug,'failed','failed',100,['commit'=>(string)($meta['commit']??''),'artifactId'=>(string)($meta['artifactId']??''),'requestId'=>(string)($meta['requestId']??''),'uploadId'=>$id,'error'=>$e->getMessage()]);$GLOBALS['DIGIOPS_ACTIVE_DEPLOYMENT']=null;throw $e;}}
    if($action==='rollback'){
        [$slug,$publicTarget,$privateTarget]=projectPaths($payload);
        $release=(string)($payload['release']??'');
        if(!preg_match('/^[A-Za-z0-9._-]{3,100}$/',$release))fail('INVALID_RELEASE_ID');
        $runtime=projectRuntime($slug);ensureDir($runtime,0700);
        $lock=fopen($runtime.'/deploy.lock','c+');
        if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))fail('DEPLOYMENT_LOCKED',409);
        $root=$runtime.'/releases/'.$release;
        if(!is_dir($root.'/public'))fail('RELEASE_NOT_FOUND',404);
        $tmp=dirname($publicTarget).'/.'.$slug.'.rollback-'.bin2hex(random_bytes(4));
        copyDir($root.'/public',$tmp);
        if(is_dir($root.'/private')){validatePrivatePayload($slug,$root.'/private');ensureDir($privateTarget);copyDir($root.'/private',$privateTarget);}
        atomicSwitchDir($tmp,$publicTarget,$slug);
        $meta=readJsonFile($root.'/meta.json',[]);
        writeJsonFile($runtime.'/current.json',['release'=>$release,'commit'=>(string)($meta['commit']??'—'),'lastDeploy'=>date(DATE_ATOM)]);
        flock($lock,LOCK_UN);fclose($lock);
        ok(['release'=>$release,'commit'=>$meta['commit']??'—']);
    }
    fail('UNKNOWN_ACTION',404);
}catch(Throwable $e){ fail($e->getMessage(),400); }
