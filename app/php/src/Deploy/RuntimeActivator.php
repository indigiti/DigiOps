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

    public static function assertAvailable(): array
    {
        $capability=self::capability();
        if(empty($capability['available'])){
            throw new RuntimeException((string)($capability['reason']??'RUNTIME_ACTIVATION_EXECUTOR_UNAVAILABLE'));
        }
        return $capability;
    }

    public static function run(string $hook,string $expectedCommit,string $cwd): array
    {
        if(!is_file($hook))return ['required'=>false,'status'=>'NOT_REQUIRED'];
        $expectedCommit=strtolower(trim($expectedCommit));
        if(!preg_match('/^[a-f0-9]{40}$/',$expectedCommit)){
            throw new RuntimeException('RUNTIME_ACTIVATION_EXPECTED_COMMIT_INVALID');
        }
        if(!is_dir($cwd))throw new RuntimeException('RUNTIME_ACTIVATION_WORKDIR_MISSING');

        $capability=self::assertAvailable();
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
