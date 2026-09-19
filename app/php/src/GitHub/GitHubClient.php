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
        return $this->get('/repos/' . rawurlencode(explode('/', $fullName, 2)[0]) . '/' . rawurlencode(explode('/', $fullName, 2)[1]));
    }

    public function branches(string $fullName): array
    {
        return $this->get('/repos/' . $this->repoPath($fullName) . '/branches?per_page=100');
    }

    public function commits(string $fullName, string $branch, int $limit = 20): array
    {
        return $this->get('/repos/' . $this->repoPath($fullName) . '/commits?sha=' . rawurlencode($branch) . '&per_page=' . max(1,min(100,$limit)));
    }

    public function workflowRuns(string $fullName, string $branch, int $limit = 10): array
    {
        return $this->get('/repos/' . $this->repoPath($fullName) . '/actions/runs?branch=' . rawurlencode($branch) . '&per_page=' . max(1,min(100,$limit)));
    }

    public function artifacts(string $fullName, int $runId): array
    {
        return $this->get('/repos/' . $this->repoPath($fullName) . '/actions/runs/' . $runId . '/artifacts?per_page=100');
    }

    public function downloadArtifact(string $fullName, int $artifactId, string $target): void
    {
        $this->download('/repos/' . $this->repoPath($fullName) . '/actions/artifacts/' . $artifactId . '/zip', $target);
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
        $fp = fopen($target, 'wb');
        if (!$fp) throw new RuntimeException('ARTIFACT_TARGET_OPEN_FAILED');
        $ch = curl_init('https://api.github.com' . $path);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => [
                'Accept: application/vnd.github+json',
                'Authorization: Bearer ' . $this->token,
                'User-Agent: DigiOps/1.0',
                'X-GitHub-Api-Version: 2022-11-28',
            ],
        ]);
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        fclose($fp);
        if (!$ok || $status < 200 || $status >= 300) {
            @unlink($target);
            throw new RuntimeException('ARTIFACT_DOWNLOAD_FAILED_' . $status);
        }
    }
}
