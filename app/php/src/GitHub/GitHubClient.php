<?php
declare(strict_types=1);

namespace DigiOps\GitHub;

use RuntimeException;

final class GitHubClient
{
    private const ARTIFACT_CONNECT_TIMEOUT = 30;
    private const ARTIFACT_TRANSFER_TIMEOUT = 600;
    private const ARTIFACT_ATTEMPTS = 3;

    private string $token;

    public function __construct(string $token)
    {
        $this->token = trim($token);
        if ($this->token === '') throw new RuntimeException('GITHUB_TOKEN_MISSING');
    }

    public function repo(string $fullName): array
    {
        try {
            return $this->get('/repos/' . $this->repoPath($fullName));
        } catch (RuntimeException $e) {
            throw $this->contextualize('REPOSITORY', $e);
        }
    }

    public function branches(string $fullName): array
    {
        try {
            return $this->get('/repos/' . $this->repoPath($fullName) . '/branches?per_page=100');
        } catch (RuntimeException $e) {
            throw $this->contextualize('BRANCHES', $e);
        }
    }

    public function commits(string $fullName, string $branch, int $limit = 20): array
    {
        try {
            return $this->get('/repos/' . $this->repoPath($fullName) . '/commits?sha=' . rawurlencode($branch) . '&per_page=' . max(1,min(100,$limit)));
        } catch (RuntimeException $e) {
            throw $this->contextualize('COMMITS', $e);
        }
    }

    public function workflowRuns(string $fullName, string $branch, int $limit = 10): array
    {
        try {
            return $this->get('/repos/' . $this->repoPath($fullName) . '/actions/runs?branch=' . rawurlencode($branch) . '&per_page=' . max(1,min(100,$limit)));
        } catch (RuntimeException $e) {
            throw $this->contextualize('ACTIONS', $e);
        }
    }

    public function artifacts(string $fullName, int $runId): array
    {
        try {
            return $this->get('/repos/' . $this->repoPath($fullName) . '/actions/runs/' . $runId . '/artifacts?per_page=100');
        } catch (RuntimeException $e) {
            throw $this->contextualize('ARTIFACTS', $e);
        }
    }

    public function workflowRun(string $fullName, int $runId): array
    {
        if ($runId <= 0) throw new RuntimeException('INVALID_WORKFLOW_RUN');
        try {
            return $this->get('/repos/' . $this->repoPath($fullName) . '/actions/runs/' . $runId);
        } catch (RuntimeException $e) {
            throw $this->contextualize('ACTIONS', $e);
        }
    }

    public function downloadArtifact(
        string $fullName,
        int $artifactId,
        string $target,
        string $expectedDigest = '',
        ?int $expectedBytes = null
    ): void {
        $this->download(
            '/repos/' . $this->repoPath($fullName) . '/actions/artifacts/' . $artifactId . '/zip',
            $target,
            strtolower(trim($expectedDigest)),
            $expectedBytes
        );
    }

    private function contextualize(string $stage, RuntimeException $e): RuntimeException
    {
        $message = $e->getMessage();
        if (preg_match('/^GITHUB_HTTP_(\d{3})(?::.*)?$/', $message, $match)) {
            return new RuntimeException('GITHUB_' . $stage . '_HTTP_' . $match[1], 0, $e);
        }
        return new RuntimeException('GITHUB_' . $stage . '_FAILED', 0, $e);
    }

    private function repoPath(string $fullName): string
    {
        if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $fullName)) throw new RuntimeException('INVALID_REPOSITORY');
        [$owner,$repo] = explode('/', $fullName, 2);
        return rawurlencode($owner) . '/' . rawurlencode($repo);
    }

    private function get(string $path): array
    {
        $body = $this->request($path, false);
        $data = json_decode($body, true);
        if (!is_array($data)) throw new RuntimeException('GITHUB_INVALID_JSON');
        return $data;
    }

    private function request(string $path, bool $binary): string
    {
        $url = 'https://api.github.com' . $path;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => [
                'Accept: application/vnd.github+json',
                'Authorization: Bearer ' . $this->token,
                'User-Agent: DigiOps/1.0',
                'X-GitHub-Api-Version: 2022-11-28',
            ],
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('GITHUB_HTTP_' . $status . ($err ? ':' . $err : ''));
        }
        return (string)$body;
    }

    private function download(string $path, string $target, string $expectedDigest, ?int $expectedBytes): void
    {
        @unlink($target);
        $lastError=null;

        for($attempt=1;$attempt<=self::ARTIFACT_ATTEMPTS;$attempt++){
            $part=$target.'.part-'.$attempt.'-'.bin2hex(random_bytes(4));
            try{
                $this->downloadOnce($path,$part);
                $this->assertZipFile($part);

                $actualBytes=filesize($part);
                if($actualBytes===false || $actualBytes<1) throw new RuntimeException('ARTIFACT_EMPTY');
                if($expectedBytes!==null && $expectedBytes>0 && $actualBytes!==$expectedBytes){
                    throw new RuntimeException('ARTIFACT_SIZE_MISMATCH_EXPECTED_'.$expectedBytes.'_RECEIVED_'.$actualBytes);
                }

                if(str_starts_with($expectedDigest,'sha256:')){
                    $expected=substr($expectedDigest,7);
                    $actual=strtolower((string)hash_file('sha256',$part));
                    if(!preg_match('/^[a-f0-9]{64}$/',$expected) || !hash_equals($expected,$actual)){
                        throw new RuntimeException('ARTIFACT_DIGEST_MISMATCH');
                    }
                }

                if(!@rename($part,$target)){
                    throw new RuntimeException('ARTIFACT_PROMOTE_FAILED');
                }
                return;
            }catch(RuntimeException $e){
                @unlink($part);
                $lastError=$e;
                if($attempt>=self::ARTIFACT_ATTEMPTS || !$this->isRetryableArtifactError($e->getMessage())){
                    throw $e;
                }
                usleep(250000 * (2 ** ($attempt-1)));
            }
        }

        throw $lastError ?? new RuntimeException('ARTIFACT_DOWNLOAD_FAILED');
    }

    private function downloadOnce(string $path, string $part): void
    {
        $apiUrl = 'https://api.github.com' . $path;
        $location = null;

        $fp = fopen($part, 'wb');
        if (!$fp) throw new RuntimeException('ARTIFACT_TARGET_OPEN_FAILED');

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => self::ARTIFACT_CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::ARTIFACT_TRANSFER_TIMEOUT,
            CURLOPT_HTTPHEADER => [
                'Accept: application/vnd.github+json',
                'Authorization: Bearer ' . $this->token,
                'User-Agent: DigiOps/1.0',
                'X-GitHub-Api-Version: 2022-11-28',
            ],
            CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$location): int {
                $len = strlen($header);
                if (stripos($header, 'Location:') === 0) {
                    $location = trim(substr($header, 9));
                }
                return $len;
            },
        ]);

        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $expected = $this->curlContentLength($ch);
        $received = $this->curlDownloadedBytes($ch, $part);
        curl_close($ch);
        fclose($fp);

        if ($ok === false) {
            throw new RuntimeException($this->artifactTransferError('API',$status,$errno,$expected,$received,$err));
        }

        if ($status >= 200 && $status < 300) {
            return;
        }

        if (!in_array($status, [301,302,303,307,308], true) || !$location) {
            throw new RuntimeException('ARTIFACT_REDIRECT_FAILED_' . $status);
        }

        $parts = parse_url($location);
        if (
            !is_array($parts)
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            throw new RuntimeException('ARTIFACT_REDIRECT_INVALID');
        }

        // GitHub returns a short-lived signed blob URL. Reopen the partial file
        // from byte zero and deliberately do not forward GitHub Authorization.
        $fp = fopen($part, 'wb');
        if (!$fp) throw new RuntimeException('ARTIFACT_TARGET_OPEN_FAILED');

        $ch = curl_init($location);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => self::ARTIFACT_CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::ARTIFACT_TRANSFER_TIMEOUT,
            CURLOPT_HTTPHEADER => [
                'Accept: application/octet-stream',
                'User-Agent: DigiOps/1.0',
            ],
        ]);

        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $expected = $this->curlContentLength($ch);
        $received = $this->curlDownloadedBytes($ch, $part);
        curl_close($ch);
        fclose($fp);

        if ($ok === false || $status < 200 || $status >= 300) {
            throw new RuntimeException($this->artifactTransferError('BLOB',$status,$errno,$expected,$received,$err));
        }

        if($expected>0 && $received!==$expected){
            throw new RuntimeException('ARTIFACT_BLOB_INCOMPLETE_EXPECTED_'.$expected.'_RECEIVED_'.$received);
        }
    }

    private function isRetryableArtifactError(string $message): bool
    {
        if(preg_match('/^ARTIFACT_(?:API|BLOB)_FAILED_\d{1,3}_CURL_(?:6|7|18|28|35|52|55|56|92)(?:_|:|$)/',$message)) return true;
        if(preg_match('/^ARTIFACT_(?:API|BLOB)_FAILED_(?:408|425|429|5\d\d)_CURL_0(?:_|:|$)/',$message)) return true;
        if(str_starts_with($message,'ARTIFACT_BLOB_INCOMPLETE_')) return true;
        return false;
    }

    private function artifactTransferError(string $stage, int $status, int $errno, int $expected, int $received, string $error): string
    {
        $message='ARTIFACT_'.$stage.'_FAILED_'.$status.'_CURL_'.$errno.'_EXPECTED_'.$expected.'_RECEIVED_'.$received;
        if($error!=='')$message.=':'.preg_replace('/\s+/',' ',trim($error));
        return $message;
    }

    private function curlContentLength($ch): int
    {
        if(defined('CURLINFO_CONTENT_LENGTH_DOWNLOAD_T')){
            $value=curl_getinfo($ch,CURLINFO_CONTENT_LENGTH_DOWNLOAD_T);
            if(is_int($value) && $value>=0) return $value;
        }
        $value=curl_getinfo($ch,CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        return is_numeric($value) && (float)$value>=0 ? (int)$value : -1;
    }

    private function curlDownloadedBytes($ch, string $part): int
    {
        if(defined('CURLINFO_SIZE_DOWNLOAD_T')){
            $value=curl_getinfo($ch,CURLINFO_SIZE_DOWNLOAD_T);
            if(is_int($value) && $value>=0) return $value;
        }
        clearstatcache(true,$part);
        $size=is_file($part)?filesize($part):false;
        return $size===false ? 0 : (int)$size;
    }

    private function assertZipFile(string $target): void
    {
        if (!is_file($target) || filesize($target) < 22) {
            @unlink($target);
            throw new RuntimeException('ARTIFACT_EMPTY');
        }

        $fh = fopen($target, 'rb');
        if (!$fh) {
            @unlink($target);
            throw new RuntimeException('ARTIFACT_READ_FAILED');
        }
        $magic = fread($fh, 4);
        fclose($fh);

        if (!in_array($magic, ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"], true)) {
            @unlink($target);
            throw new RuntimeException('ARTIFACT_NOT_ZIP');
        }
    }
}
