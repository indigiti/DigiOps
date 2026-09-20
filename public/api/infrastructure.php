<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use DigiOps\Audit\AuditLog;
use DigiOps\Security\SecretVault;
use DigiOps\Security\Session;
use DigiOps\Support\Files;
use DigiOps\Support\JsonResponse;

$user = Session::requireRole();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$vault = new SecretVault();
$configFile = DIGIOPS_PRIVATE_ROOT . '/config/infrastructure.json';

function infraConfig(string $file): array
{
    $defaults = [
        'varnish'=>[
            'enabled'=>true,
            'bypassPath'=>'/digiops/',
            'sessionCookie'=>'DIGIOPSSESSID',
            'apiPath'=>'/digiops/api/',
        ],
    ];
    $saved = Files::readJson($file, []);
    return array_replace_recursive($defaults, is_array($saved) ? $saved : []);
}

function redisConfig(SecretVault $vault): array
{
    $raw = $vault->get('redis.config');
    if (!$raw) {
        return [
            'host'=>'127.0.0.1',
            'port'=>6379,
            'username'=>'',
            'password'=>'',
            'database'=>0,
            'prefix'=>'digiops:',
            'timeout'=>1.5,
        ];
    }
    $cfg = json_decode($raw, true);
    if (!is_array($cfg)) throw new RuntimeException('REDIS_CONFIG_INVALID');
    return array_replace([
        'host'=>'127.0.0.1',
        'port'=>6379,
        'username'=>'',
        'password'=>'',
        'database'=>0,
        'prefix'=>'digiops:',
        'timeout'=>1.5,
    ], $cfg);
}

function redisRespEncode(array $parts): string
{
    $out = '*' . count($parts) . "\r\n";
    foreach ($parts as $part) {
        $part = (string)$part;
        $out .= '$' . strlen($part) . "\r\n" . $part . "\r\n";
    }
    return $out;
}

function redisRespRead($fp)
{
    $line = fgets($fp);
    if ($line === false) throw new RuntimeException('REDIS_NO_RESPONSE');
    $type = $line[0] ?? '';
    $payload = rtrim(substr($line, 1), "\r\n");

    if ($type === '+') return $payload;
    if ($type === '-') throw new RuntimeException('REDIS_ERROR:' . $payload);
    if ($type === ':') return (int)$payload;
    if ($type === '$') {
        $len = (int)$payload;
        if ($len < 0) return null;
        $data = '';
        while (strlen($data) < $len) {
            $chunk = fread($fp, $len - strlen($data));
            if ($chunk === false || $chunk === '') throw new RuntimeException('REDIS_READ_FAILED');
            $data .= $chunk;
        }
        fread($fp, 2);
        return $data;
    }
    if ($type === '*') {
        $count = (int)$payload;
        $items = [];
        for ($i=0; $i<$count; $i++) $items[] = redisRespRead($fp);
        return $items;
    }
    throw new RuntimeException('REDIS_PROTOCOL_ERROR');
}

function redisRawCommand($fp, array $parts)
{
    $cmd = redisRespEncode($parts);
    $written = fwrite($fp, $cmd);
    if ($written === false || $written !== strlen($cmd)) throw new RuntimeException('REDIS_WRITE_FAILED');
    return redisRespRead($fp);
}

function redisTest(array $cfg): array
{
    $host = trim((string)($cfg['host'] ?? '127.0.0.1'));
    $port = (int)($cfg['port'] ?? 6379);
    $user = trim((string)($cfg['username'] ?? ''));
    $password = (string)($cfg['password'] ?? '');
    $database = max(0, min(15, (int)($cfg['database'] ?? 0)));
    $timeout = max(0.3, min(5.0, (float)($cfg['timeout'] ?? 1.5)));

    if ($host === '' || $port < 1 || $port > 65535) throw new RuntimeException('REDIS_ENDPOINT_INVALID');

    $started = microtime(true);
    $extension = class_exists('Redis') ? 'phpredis' : 'raw-resp';
    $version = null;
    $memory = null;

    if (class_exists('Redis')) {
        $redis = new Redis();
        if (!$redis->connect($host, $port, $timeout)) throw new RuntimeException('REDIS_CONNECT_FAILED');
        try {
            if ($password !== '') {
                $auth = $user !== '' ? [$user, $password] : $password;
                if (!$redis->auth($auth)) throw new RuntimeException('REDIS_AUTH_FAILED');
            }
            if ($database > 0 && !$redis->select($database)) throw new RuntimeException('REDIS_SELECT_FAILED');
            if ($redis->ping() === false) throw new RuntimeException('REDIS_PING_FAILED');
            $info = $redis->info();
            if (is_array($info)) {
                $version = $info['redis_version'] ?? null;
                $memory = $info['used_memory_human'] ?? null;
            }
        } finally {
            try { $redis->close(); } catch (Throwable) {}
        }
    } else {
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
        if (!is_resource($fp)) throw new RuntimeException('REDIS_CONNECT_FAILED:' . $errno);
        stream_set_timeout($fp, (int)ceil($timeout));
        try {
            if ($password !== '') {
                $auth = $user !== '' ? ['AUTH', $user, $password] : ['AUTH', $password];
                redisRawCommand($fp, $auth);
            }
            if ($database > 0) redisRawCommand($fp, ['SELECT', (string)$database]);
            $pong = redisRawCommand($fp, ['PING']);
            if (strtoupper((string)$pong) !== 'PONG') throw new RuntimeException('REDIS_PING_FAILED');
            $infoRaw = (string)redisRawCommand($fp, ['INFO', 'server']);
            if (preg_match('/^redis_version:([^\r\n]+)/m', $infoRaw, $m)) $version = trim($m[1]);
            $memoryRaw = (string)redisRawCommand($fp, ['INFO', 'memory']);
            if (preg_match('/^used_memory_human:([^\r\n]+)/m', $memoryRaw, $m)) $memory = trim($m[1]);
        } finally {
            fclose($fp);
        }
    }

    return [
        'ok'=>true,
        'latencyMs'=>round((microtime(true)-$started)*1000, 1),
        'driver'=>$extension,
        'version'=>$version,
        'memory'=>$memory,
        'database'=>$database,
    ];
}

try {
    $infra = infraConfig($configFile);

    if ($method === 'GET') {
        $action = (string)($_GET['action'] ?? '');
        if ($action === 'probe') {
            $nonce = bin2hex(random_bytes(8));
            header('Cache-Control: no-store, private, max-age=0, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
            header('X-DigiOps-Cache-Probe: ' . $nonce);
            JsonResponse::send(['ok'=>true,'nonce'=>$nonce,'time'=>microtime(true)]);
        }

        $redis = redisConfig($vault);
        JsonResponse::send([
            'ok'=>true,
            'redis'=>[
                'configured'=>$vault->has('redis.config'),
                'host'=>$redis['host'],
                'port'=>$redis['port'],
                'username'=>$redis['username'],
                'passwordSet'=>(string)$redis['password'] !== '',
                'database'=>$redis['database'],
                'prefix'=>$redis['prefix'],
                'timeout'=>$redis['timeout'],
            ],
            'varnish'=>$infra['varnish'],
            'serviceControl'=>false,
        ]);
    }

    if (($user['role'] ?? '') !== 'admin') JsonResponse::send(['error'=>'FORBIDDEN'],403);
    Session::assertCsrf();

    $data = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($data)) JsonResponse::send(['error'=>'INVALID_JSON'],400);
    $action = (string)($data['action'] ?? '');

    if ($action === 'redis-save' || $action === 'redis-test') {
        $current = redisConfig($vault);
        $password = array_key_exists('password', $data) && (string)$data['password'] !== ''
            ? (string)$data['password']
            : (string)$current['password'];

        $cfg = [
            'host'=>trim((string)($data['host'] ?? $current['host'])),
            'port'=>(int)($data['port'] ?? $current['port']),
            'username'=>trim((string)($data['username'] ?? $current['username'])),
            'password'=>$password,
            'database'=>(int)($data['database'] ?? $current['database']),
            'prefix'=>trim((string)($data['prefix'] ?? $current['prefix'])),
            'timeout'=>(float)($data['timeout'] ?? $current['timeout']),
        ];
        $result = redisTest($cfg);

        if ($action === 'redis-save') {
            $vault->put('redis.config', json_encode($cfg, JSON_UNESCAPED_SLASHES));
            (new AuditLog())->write('REDIS_CONFIG_SAVED',[
                'host'=>$cfg['host'],
                'port'=>$cfg['port'],
                'database'=>$cfg['database'],
                'prefix'=>$cfg['prefix'],
                'driver'=>$result['driver'],
            ],$user);
        }
        JsonResponse::send(['ok'=>true,'test'=>$result,'saved'=>$action==='redis-save']);
    }

    if ($action === 'varnish-save') {
        $varnish = [
            'enabled'=>(bool)($data['enabled'] ?? true),
            'bypassPath'=>trim((string)($data['bypassPath'] ?? '/digiops/')),
            'sessionCookie'=>trim((string)($data['sessionCookie'] ?? 'DIGIOPSSESSID')),
            'apiPath'=>trim((string)($data['apiPath'] ?? '/digiops/api/')),
        ];
        if ($varnish['bypassPath'] === '' || $varnish['bypassPath'][0] !== '/') throw new RuntimeException('VARNISH_BYPASS_PATH_INVALID');
        if ($varnish['apiPath'] === '' || $varnish['apiPath'][0] !== '/') throw new RuntimeException('VARNISH_API_PATH_INVALID');
        if (!preg_match('/^[A-Za-z0-9_-]{3,80}$/', $varnish['sessionCookie'])) throw new RuntimeException('VARNISH_COOKIE_INVALID');

        $infra['varnish'] = $varnish;
        Files::writeJson($configFile, $infra);
        (new AuditLog())->write('VARNISH_POLICY_SAVED',$varnish,$user);
        JsonResponse::send(['ok'=>true,'varnish'=>$varnish]);
    }

    JsonResponse::send(['error'=>'UNKNOWN_ACTION'],400);
} catch (Throwable $e) {
    JsonResponse::send(['error'=>$e->getMessage()],400);
}
