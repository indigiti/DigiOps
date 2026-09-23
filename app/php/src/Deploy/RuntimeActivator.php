<?php
declare(strict_types=1);

namespace DigiOps\Deploy;

use RuntimeException;

final class RuntimeActivator
{
    public static function selectExecutor(bool $procOpenAvailable,bool $execAvailable,bool $timeoutAvailable): string
    {
        if($procOpenAvailable)return 'proc_open';
        if($execAvailable && $timeoutAvailable)return 'exec';
        return '';
    }

    public static function selectTransport(bool $shellAvailable,bool $watchdogAvailable): string
    {
        if($shellAvailable)return 'shell';
        if($watchdogAvailable)return 'watchdog';
        return '';
    }

    public static function capability(): array
    {
        $python=self::commandPath('python3');
        $timeout=self::commandPath('timeout');
        $procOpenAvailable=\function_exists('proc_open')
            && \function_exists('proc_get_status')
            && \function_exists('proc_terminate')
            && \function_exists('proc_close');
        $execAvailable=\function_exists('exec');
        $executor=self::selectExecutor($procOpenAvailable,$execAvailable,$timeout!==null);

        $reason='';
        if($python===null){
            $reason='RUNTIME_ACTIVATION_PYTHON3_UNAVAILABLE';
        }elseif($executor===''){
            if(!$procOpenAvailable && $execAvailable && $timeout===null){
                $reason='RUNTIME_ACTIVATION_TIMEOUT_TOOL_UNAVAILABLE';
            }else{
                $reason='RUNTIME_ACTIVATION_EXECUTOR_UNAVAILABLE';
            }
        }

        return [
            'available'=>$reason==='',
            'executor'=>$reason===''?$executor:'',
            'python'=>$python,
            'timeout'=>$timeout,
            'proc_open_available'=>$procOpenAvailable,
            'exec_available'=>$execAvailable,
            'reason'=>$reason,
        ];
    }

    public static function preflight(string $stagedPrivate,string $targetPrivate): array
    {
        $hook=rtrim($stagedPrivate,'/').'/scripts/digiops-runtime-activate.py';
        if(!is_file($hook))return ['required'=>false,'transport'=>'none'];

        $shell=self::capability();
        $watchdog=self::watchdogCapability($stagedPrivate,$targetPrivate);
        $transport=self::selectTransport(!empty($shell['available']),!empty($watchdog['available']));
        if($transport==='shell'){
            return [
                'required'=>true,
                'transport'=>'shell',
                'executor'=>(string)$shell['executor'],
            ];
        }
        if($transport==='watchdog'){
            return ['required'=>true,'transport'=>'watchdog']+$watchdog;
        }

        $reason=(string)($watchdog['reason']??'');
        if($reason==='')$reason=(string)($shell['reason']??'RUNTIME_ACTIVATION_UNAVAILABLE');
        throw new RuntimeException($reason);
    }

    public static function run(string $hook,string $expectedCommit,string $cwd,array $plan=[]): array
    {
        if(!is_file($hook))return ['required'=>false,'status'=>'NOT_REQUIRED'];
        $expectedCommit=strtolower(trim($expectedCommit));
        if(!preg_match('/^[a-f0-9]{40}$/',$expectedCommit)){
            throw new RuntimeException('RUNTIME_ACTIVATION_EXPECTED_COMMIT_INVALID');
        }
        if(!is_dir($cwd))throw new RuntimeException('RUNTIME_ACTIVATION_WORKDIR_MISSING');

        $transport=(string)($plan['transport']??'');
        if($transport==='watchdog'){
            $payload=self::runWatchdog($expectedCommit,$cwd,$plan);
            return ['required'=>true,'status'=>'SUCCESS','executor'=>'watchdog_request']+$payload;
        }

        $capability=self::capability();
        if(empty($capability['available'])){
            // A deployment may reach this path without an explicit preflight plan
            // (rollback/older caller). Re-evaluate the target watchdog safely.
            $fallback=self::watchdogCapability($cwd,$cwd);
            if(!empty($fallback['available'])){
                $payload=self::runWatchdog($expectedCommit,$cwd,$fallback);
                return ['required'=>true,'status'=>'SUCCESS','executor'=>'watchdog_request']+$payload;
            }
            throw new RuntimeException((string)($fallback['reason']??$capability['reason']??'RUNTIME_ACTIVATION_UNAVAILABLE'));
        }

        $executor=(string)$capability['executor'];
        $python=(string)$capability['python'];
        if($executor==='proc_open'){
            $payload=self::runProcOpen($python,$hook,$expectedCommit,$cwd);
        }elseif($executor==='exec'){
            $payload=self::runExec(
                (string)$capability['timeout'],
                $python,
                $hook,
                $expectedCommit,
                $cwd
            );
        }else{
            throw new RuntimeException('RUNTIME_ACTIVATION_EXECUTOR_UNAVAILABLE');
        }

        return ['required'=>true,'status'=>'SUCCESS','executor'=>$executor]+$payload;
    }

    private static function watchdogCapability(string $stagedPrivate,string $targetPrivate): array
    {
        $watchdog=rtrim($stagedPrivate,'/').'/go-engine/watchdog.sh';
        $registry=rtrim($stagedPrivate,'/').'/config/modules.json';
        $runDir=rtrim($targetPrivate,'/').'/go-engine/run';
        $stateFile=$runDir.'/watchdog-state.json';

        if(!is_file($watchdog)){
            return ['available'=>false,'reason'=>'RUNTIME_ACTIVATION_WATCHDOG_MISSING'];
        }
        if(!is_file($registry)){
            return ['available'=>false,'reason'=>'RUNTIME_ACTIVATION_MODULE_REGISTRY_MISSING'];
        }
        $data=json_decode((string)@file_get_contents($registry),true);
        $address=is_array($data)?trim((string)($data['modules']['api-engine']['address']??'')):'';
        if(!preg_match('/^127\.0\.0\.1:\d{2,5}$/',$address)){
            return ['available'=>false,'reason'=>'RUNTIME_ACTIVATION_API_ADDRESS_INVALID'];
        }
        if(!\function_exists('curl_init')){
            return ['available'=>false,'reason'=>'RUNTIME_ACTIVATION_HTTP_CLIENT_UNAVAILABLE'];
        }
        if(!is_dir($runDir) || !is_writable($runDir)){
            return ['available'=>false,'reason'=>'RUNTIME_ACTIVATION_WATCHDOG_RUN_DIR_NOT_WRITABLE'];
        }
        $state=json_decode((string)@file_get_contents($stateFile),true);
        $lastRun=is_array($state)?(int)($state['last_run_unix']??0):0;
        if($lastRun<=0){
            return ['available'=>false,'reason'=>'RUNTIME_ACTIVATION_WATCHDOG_STATE_MISSING'];
        }
        $age=max(0,time()-$lastRun);
        if($age>180){
            return ['available'=>false,'reason'=>'RUNTIME_ACTIVATION_WATCHDOG_NOT_RECENT'];
        }

        return [
            'available'=>true,
            'reason'=>'',
            'api_address'=>$address,
            'watchdog_state'=>$stateFile,
            'watchdog_age_seconds'=>$age,
            'restart_request'=>$runDir.'/module-restart-request.json',
        ];
    }

    private static function runWatchdog(string $expectedCommit,string $cwd,array $plan): array
    {
        $request=(string)($plan['restart_request']??(rtrim($cwd,'/').'/go-engine/run/module-restart-request.json'));
        $runDir=dirname($request);
        if(!is_dir($runDir) || !is_writable($runDir)){
            throw new RuntimeException('RUNTIME_ACTIVATION_WATCHDOG_RUN_DIR_NOT_WRITABLE');
        }
        if(is_file($request)){
            $age=max(0,time()-(int)(@filemtime($request)?:time()));
            if($age<300)throw new RuntimeException('RUNTIME_ACTIVATION_REQUEST_ALREADY_PENDING');
            @unlink($request);
        }

        $payload=[
            'action'=>'DIGIOPS_ACTIVATE_RELEASE',
            'expected_commit'=>$expectedCommit,
            'requested_at'=>date(DATE_ATOM),
            'requested_unix'=>time(),
        ];
        $tmp=$request.'.tmp-'.bin2hex(random_bytes(4));
        if(@file_put_contents($tmp,json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL,LOCK_EX)===false){
            @unlink($tmp);
            throw new RuntimeException('RUNTIME_ACTIVATION_REQUEST_WRITE_FAILED');
        }
        @chmod($tmp,0640);
        if(!@rename($tmp,$request)){
            @unlink($tmp);
            throw new RuntimeException('RUNTIME_ACTIVATION_REQUEST_PUBLISH_FAILED');
        }

        $address=(string)($plan['api_address']??'');
        if(!preg_match('/^127\.0\.0\.1:\d{2,5}$/',$address)){
            $registry=rtrim($cwd,'/').'/config/modules.json';
            $data=json_decode((string)@file_get_contents($registry),true);
            $address=is_array($data)?trim((string)($data['modules']['api-engine']['address']??'')):'';
        }
        if(!preg_match('/^127\.0\.0\.1:\d{2,5}$/',$address)){
            throw new RuntimeException('RUNTIME_ACTIVATION_API_ADDRESS_INVALID');
        }

        $deadline=microtime(true)+150;
        $last=[];
        while(microtime(true)<$deadline){
            [$diagStatus,$diag]=self::getJson('http://'.$address.'/v1/diagnostics/pipeline?profile=live');
            [$platformStatus,$platform]=self::getJson('http://'.$address.'/v1/platform?profile=live');
            $authority=is_array($platform['provider_authority']??null)?$platform['provider_authority']:[];
            $last=[
                'diagnostics_http'=>$diagStatus,
                'platform_http'=>$platformStatus,
                'running_commit'=>(string)($diag['running_commit']??''),
                'release_id'=>(string)($diag['release_id']??''),
                'profile'=>(string)($diag['profile']??($platform['stack_profile']??'')),
                'commit_consistent'=>(bool)($diag['commit_consistent']??false),
                'release_consistent'=>(bool)($diag['release_consistent']??false),
                'config_consistent'=>(bool)($diag['config_consistent']??false),
                'provider_authority_ok'=>(bool)($authority['ok']??false),
                'violations'=>$platform['data_authority_violations']??($authority['violations']??[]),
            ];
            $running=strtolower(trim((string)($diag['running_commit']??'')));
            $profile=strtolower(trim((string)($diag['profile']??($platform['stack_profile']??''))));
            if(
                $diagStatus>=200 && $diagStatus<300
                && $platformStatus>=200 && $platformStatus<300
                && $running===$expectedCommit
                && $profile==='live'
                && ($diag['commit_consistent']??false)===true
                && ($diag['release_consistent']??false)===true
                && ($diag['config_consistent']??false)===true
                && ($authority['ok']??false)===true
            ){
                return [
                    'publication'=>'SUCCESS',
                    'runtime_activation'=>'SUCCESS',
                    'runtime_certification'=>'SUCCESS',
                    'profile'=>'live',
                    'running_commit'=>$running,
                    'release_id'=>(string)($diag['release_id']??''),
                    'config_fingerprint'=>(string)($diag['config_fingerprint']??''),
                    'live_authority'=>[
                        'ok'=>true,
                        'provider_authority'=>$authority,
                        'violations'=>$platform['data_authority_violations']??[],
                    ],
                    'watchdog_request'=>$request,
                ];
            }
            usleep(500000);
        }
        throw new RuntimeException('RUNTIME_ACTIVATION_TIMEOUT:'.substr(json_encode($last,JSON_UNESCAPED_SLASHES)?:'',0,1800));
    }

    private static function getJson(string $url): array
    {
        if(!\function_exists('curl_init'))return [0,[]];
        $ch=\curl_init($url);
        if($ch===false)return [0,[]];
        \curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_CONNECTTIMEOUT=>1,
            CURLOPT_TIMEOUT=>3,
            CURLOPT_HTTPHEADER=>['Accept: application/json','Connection: close'],
        ]);
        $raw=\curl_exec($ch);
        $status=(int)\curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
        \curl_close($ch);
        if(!is_string($raw) || $raw==='')return [$status,[]];
        $data=json_decode($raw,true);
        return [$status,is_array($data)?$data:[]];
    }

    private static function runProcOpen(string $python,string $hook,string $expectedCommit,string $cwd): array
    {
        $descriptors=[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
        $proc=@\proc_open([$python,$hook,'--expected-commit',$expectedCommit],$descriptors,$pipes,$cwd);
        if(!is_resource($proc))throw new RuntimeException('RUNTIME_ACTIVATION_START_FAILED');
        fclose($pipes[0]);
        stream_set_blocking($pipes[1],false);
        stream_set_blocking($pipes[2],false);
        $stdout='';$stderr='';$deadline=microtime(true)+150;$exitCode=null;
        try{
            while(true){
                $stdout.=(string)stream_get_contents($pipes[1]);
                $stderr.=(string)stream_get_contents($pipes[2]);
                $status=\proc_get_status($proc);
                if(!($status['running']??false)){
                    $exitCode=(int)($status['exitcode']??-1);
                    break;
                }
                if(microtime(true)>=$deadline){
                    @\proc_terminate($proc,15);
                    usleep(250000);
                    $status=\proc_get_status($proc);
                    if($status['running']??false)@\proc_terminate($proc,9);
                    throw new RuntimeException('RUNTIME_ACTIVATION_TIMEOUT');
                }
                usleep(100000);
            }
            $stdout.=(string)stream_get_contents($pipes[1]);
            $stderr.=(string)stream_get_contents($pipes[2]);
        }finally{
            fclose($pipes[1]);
            fclose($pipes[2]);
            $closed=\proc_close($proc);
            if($exitCode===null && is_int($closed))$exitCode=$closed;
        }
        return self::certifyOutput((int)($exitCode??-1),$stdout,$stderr);
    }

    private static function runExec(string $timeout,string $python,string $hook,string $expectedCommit,string $cwd): array
    {
        $command='cd '.escapeshellarg($cwd)
            .' && '.escapeshellarg($timeout)
            .' --signal=TERM --kill-after=2s 150s '
            .escapeshellarg($python).' '
            .escapeshellarg($hook).' --expected-commit '
            .escapeshellarg($expectedCommit).' 2>&1';
        $lines=[];$exitCode=-1;
        @\exec($command,$lines,$exitCode);
        $stdout=implode(PHP_EOL,$lines);
        if($exitCode===124 || $exitCode===137){
            throw new RuntimeException('RUNTIME_ACTIVATION_TIMEOUT');
        }
        return self::certifyOutput($exitCode,$stdout,'');
    }

    private static function certifyOutput(int $exitCode,string $stdout,string $stderr): array
    {
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
        return $payload;
    }

    private static function commandPath(string $name): ?string
    {
        if(!preg_match('/^[A-Za-z0-9._-]+$/',$name))return null;
        $path=(string)(getenv('PATH')?:'/usr/local/bin:/usr/bin:/bin');
        foreach(array_filter(explode(PATH_SEPARATOR,$path),'strlen') as $dir){
            $candidate=rtrim($dir,'/').'/'.$name;
            if(is_file($candidate) && is_executable($candidate))return $candidate;
        }
        foreach(['/usr/local/bin','/usr/bin','/bin'] as $dir){
            $candidate=$dir.'/'.$name;
            if(is_file($candidate) && is_executable($candidate))return $candidate;
        }
        return null;
    }
}
