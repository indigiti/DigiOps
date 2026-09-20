<?php
declare(strict_types=1);

namespace DigiOps\GitHub;

use RuntimeException;

final class GitHubClient
{
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

    public function downloadArtifact(string $fullName, int $artifactId, string $target): void
    {
        $this->download('/repos/' . $this->repoPath($fullName) . '/actions/artifacts/' . $artifactId . '/zip', $target);
    }

    private function contextualize(string $stage, RuntimeException $e): RuntimeException
    {
        $message = $e->getMessage();
        if (preg_match('/^GITHUB_HTTP_(\\d{3})(?::.*)?$/', $message, $match)) {
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

    private function download(string $path, string $target): void
    {
        $apiUrl = 'https://api.github.com' . $path;
        $location = null;

        $fp = fopen($target, 'wb');
        if (!$fp) throw new RuntimeException('ARTIFACT_TARGET_OPEN_FAILED');

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45,
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
        curl_close($ch);
        fclose($fp);

        if ($ok === false) {
            @unlink($target);
            throw new RuntimeException('ARTIFACT_API_FAILED_' . $status . '_CURL_' . $errno . ($err ? ':' . $err : ''));
        }

        if ($status >= 200 && $status < 300) {
            $this->assertZipFile($target);
            return;
        }

        if (!in_array($status, [301,302,303,307,308], true) || !$location) {
            @unlink($target);
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
            @unlink($target);
            throw new RuntimeException('ARTIFACT_REDIRECT_INVALID');
        }

        // GitHub returns a short-lived signed blob URL. Use a fresh request and
        // deliberately do not forward the GitHub Authorization header.
        $fp = fopen($target, 'wb');
        if (!$fp) throw new RuntimeException('ARTIFACT_TARGET_OPEN_FAILED');

        $ch = curl_init($location);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_HTTPHEADER => [
                'Accept: application/octet-stream',
                'User-Agent: DigiOps/1.0',
            ],
        ]);

        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if ($ok === false || $status < 200 || $status >= 300) {
            @unlink($target);
            throw new RuntimeException('ARTIFACT_BLOB_FAILED_' . $status . '_CURL_' . $errno . ($err ? ':' . $err : ''));
        }

        $this->assertZipFile($target);
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
