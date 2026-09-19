<?php
declare(strict_types=1);

/**
 * DigiOps Cloudways bootstrap.
 *
 * Deploy this branch to public_html/digiops/.
 * On first load it downloads the latest successful DigiOps GitHub Actions
 * artifact and installs public + private runtime files into the correct
 * Cloudways folders. The GitHub token is used only for this bootstrap request
 * and is never stored by this script.
 */

session_name('DIGIOPSBOOTSTRAP');
session_start();

const DIGIOPS_CERTIFIED_ARTIFACT_ID = 10590778542;
const DIGIOPS_CERTIFIED_SOURCE_SHA = '5116e3828cd540c34db5ece2a54aa21ea3bc7c89';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");

$publicDir = __DIR__;
$documentRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: dirname(__DIR__);
$appHome = dirname($documentRoot);
$privateRoot = $appHome . '/private_html/digiops';
$installed = is_file($publicDir . '/index.html') && is_file($privateRoot . '/app/php/bootstrap.php');

if ($installed && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && !isset($_GET['bootstrap'])) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($publicDir . '/index.html');
    exit;
}

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
$error = '';
$success = '';

function do_mkdir(string $dir, int $mode = 0755): void {
    if (is_dir($dir)) return;
    if (!mkdir($dir, $mode, true) && !is_dir($dir)) throw new RuntimeException('Cannot create directory: ' . $dir);
}

function copy_tree(string $src, string $dst): void {
    do_mkdir($dst);
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $item) {
        if ($item->isLink()) throw new RuntimeException('Symlink in release is not allowed');
        $relative = substr($item->getPathname(), strlen(rtrim($src, DIRECTORY_SEPARATOR)) + 1);
        $target = $dst . DIRECTORY_SEPARATOR . $relative;
        if ($item->isDir()) do_mkdir($target);
        else {
            do_mkdir(dirname($target));
            if (!copy($item->getPathname(), $target)) throw new RuntimeException('Failed to copy ' . $relative);
            @chmod($target, 0644);
        }
    }
}

function remove_tree(string $path): void {
    if (!file_exists($path)) return;
    if (is_file($path) || is_link($path)) { @unlink($path); return; }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        if ($item->isDir() && !$item->isLink()) @rmdir($item->getPathname());
        else @unlink($item->getPathname());
    }
    @rmdir($path);
}

function gh_request(string $url, string $token, ?string $output = null): string {
    $ch = curl_init($url);
    $headers = [
        'Accept: application/vnd.github+json',
        'Authorization: Bearer ' . $token,
        'User-Agent: DigiOps-Cloudways-Bootstrap/1.0',
        'X-GitHub-Api-Version: 2022-11-28',
    ];

    if ($output !== null) {
        $location = null;
        $fp = fopen($output, 'wb');
        if (!$fp) throw new RuntimeException('Cannot open artifact target');
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$location): int {
                $len = strlen($header);
                if (stripos($header, 'Location:') === 0) $location = trim(substr($header, 9));
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
            @unlink($output);
            throw new RuntimeException('GitHub artifact API failed: HTTP ' . $status . ' cURL ' . $errno . ($err ? ' ' . $err : ''));
        }

        if ($status >= 200 && $status < 300) return '';

        if (!in_array($status, [301,302,303,307,308], true) || !$location) {
            @unlink($output);
            throw new RuntimeException('GitHub artifact redirect failed: HTTP ' . $status);
        }

        $parts = parse_url($location);
        if (
            !is_array($parts)
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            @unlink($output);
            throw new RuntimeException('GitHub artifact redirect is invalid.');
        }

        $fp = fopen($output, 'wb');
        if (!$fp) throw new RuntimeException('Cannot open artifact target');
        $blob = curl_init($location);
        curl_setopt_array($blob, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_HTTPHEADER => [
                'Accept: application/octet-stream',
                'User-Agent: DigiOps-Cloudways-Bootstrap/1.0',
            ],
        ]);
        $ok = curl_exec($blob);
        $status = (int)curl_getinfo($blob, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($blob);
        $err = curl_error($blob);
        curl_close($blob);
        fclose($fp);

        if ($ok === false || $status < 200 || $status >= 300) {
            @unlink($output);
            throw new RuntimeException('GitHub artifact blob failed: HTTP ' . $status . ' cURL ' . $errno . ($err ? ' ' . $err : ''));
        }

        $fh = fopen($output, 'rb');
        $magic = $fh ? fread($fh, 4) : false;
        if ($fh) fclose($fh);
        if (!is_string($magic) || !in_array($magic, ["PK\x03\x04","PK\x05\x06","PK\x07\x08"], true)) {
            @unlink($output);
            throw new RuntimeException('Downloaded artifact is not a ZIP.');
        }
        return '';
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $status < 200 || $status >= 300) {
        throw new RuntimeException('GitHub request failed: HTTP ' . $status . ($err ? ' ' . $err : ''));
    }
    return (string)$body;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Security token expired. Refresh and try again.');
        }
        if (!extension_loaded('curl')) throw new RuntimeException('PHP cURL extension is required.');
        if (!extension_loaded('zip')) throw new RuntimeException('PHP ZIP extension is required.');

        $token = trim((string)($_POST['token'] ?? ''));
        if ($token === '') throw new RuntimeException('GitHub token is required.');

        $artifactJson = gh_request(
            'https://api.github.com/repos/indigiti/DigiOps/actions/artifacts/' . DIGIOPS_CERTIFIED_ARTIFACT_ID,
            $token
        );
        $artifact = json_decode($artifactJson, true);
        if (!is_array($artifact) || empty($artifact['id'])) {
            throw new RuntimeException('Certified DigiOps artifact metadata is unavailable.');
        }
        if (($artifact['expired'] ?? true) === true) {
            throw new RuntimeException('Certified DigiOps artifact has expired; publish a fresh certified release.');
        }
        if (($artifact['name'] ?? '') !== 'digiops-release') {
            throw new RuntimeException('Certified artifact name mismatch.');
        }

        $tempBase = $publicDir . '/.digiops-bootstrap-' . bin2hex(random_bytes(5));
        $zipFile = $tempBase . '.zip';
        do_mkdir($tempBase);
        gh_request(
            'https://api.github.com/repos/indigiti/DigiOps/actions/artifacts/' . DIGIOPS_CERTIFIED_ARTIFACT_ID . '/zip',
            $token,
            $zipFile
        );

        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) throw new RuntimeException('Downloaded artifact is not a valid ZIP.');
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string)$zip->getNameIndex($i));
            if ($name === '' || str_starts_with($name, '/') || str_contains($name, '../') || preg_match('#^[A-Za-z]:/#', $name)) {
                $zip->close();
                throw new RuntimeException('Unsafe path detected in artifact.');
            }
        }
        if (!$zip->extractTo($tempBase)) {
            $zip->close();
            throw new RuntimeException('Could not extract artifact.');
        }
        $zip->close();

        $releasePublic = $tempBase . '/public';
        $releasePrivate = $tempBase . '/private';
        if (!is_file($releasePublic . '/index.html')) throw new RuntimeException('Artifact public entrypoint missing.');
        if (!is_file($releasePrivate . '/app/php/bootstrap.php')) throw new RuntimeException('Artifact private runtime missing.');

        do_mkdir($privateRoot, 0750);

        // Remove only previously installed frontend payload. Keep the bootstrap
        // files from the cloudways branch and preserve all private runtime state.
        foreach (['assets', '.vite'] as $managedDir) {
            $managedPath = $publicDir . '/' . $managedDir;
            if (is_dir($managedPath)) remove_tree($managedPath);
        }
        foreach (['index.html', 'manifest.webmanifest', 'sw.js', 'favicon.ico'] as $managedFile) {
            $managedPath = $publicDir . '/' . $managedFile;
            if (is_file($managedPath)) @unlink($managedPath);
        }

        copy_tree($releasePrivate, $privateRoot);
        copy_tree($releasePublic, $publicDir);

        file_put_contents(
            $privateRoot . '/bootstrap-install.json',
            json_encode([
                'installedAt' => date(DATE_ATOM),
                'artifactId' => DIGIOPS_CERTIFIED_ARTIFACT_ID,
                'sourceSha' => DIGIOPS_CERTIFIED_SOURCE_SHA,
                'artifactCreatedAt' => $artifact['created_at'] ?? null,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
            LOCK_EX
        );

        remove_tree($tempBase);
        @unlink($zipFile);
        file_put_contents(
            $publicDir . '/.digiops-installed',
            json_encode([
                'artifactId'=>DIGIOPS_CERTIFIED_ARTIFACT_ID,
                'sourceSha'=>DIGIOPS_CERTIFIED_SOURCE_SHA,
                'installedAt'=>date(DATE_ATOM)
            ], JSON_UNESCAPED_SLASHES) . PHP_EOL,
            LOCK_EX
        );

        $success = 'DigiOps certified runtime installed successfully. Reloading…';
        header('Refresh: 2; url=./?release=' . DIGIOPS_CERTIFIED_ARTIFACT_ID);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DigiOps Cloudways Installer</title>
<style>
:root{font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#17233f;background:#eef2f7}
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px}.card{width:min(100%,520px);background:#fff;border:1px solid #dfe5ee;border-radius:24px;padding:28px;box-shadow:0 20px 60px rgba(15,23,42,.10)}.brand{display:flex;gap:12px;align-items:center;margin-bottom:24px}.logo{display:grid;place-items:center;width:44px;height:44px;border-radius:14px;background:#335eea;color:white;font-weight:800}.muted{color:#667085;font-size:14px;line-height:1.55}.notice{padding:12px 14px;border-radius:12px;margin:14px 0;font-size:14px}.error{background:#fff1f2;color:#b42318}.success{background:#ecfdf3;color:#027a48}label{display:block;font-size:13px;font-weight:700;margin:18px 0 7px}input{width:100%;border:1px solid #d0d5dd;border-radius:12px;padding:12px 13px;font:inherit}button{width:100%;border:0;border-radius:12px;background:#315bea;color:white;padding:12px 16px;font:inherit;font-weight:700;margin-top:18px;cursor:pointer}.path{margin-top:18px;background:#f8fafc;border-radius:12px;padding:12px;font:12px ui-monospace,SFMono-Regular,Menlo,monospace;color:#475467}
</style>
</head>
<body>
<div class="card">
  <div class="brand"><div class="logo">DO</div><div><strong>DigiOps</strong><div class="muted">Cloudways bootstrap installer</div></div></div>
  <p class="muted">This installer downloads the pinned certified <strong>digiops-release</strong> artifact and places the public and private runtime files in the correct Cloudways folders.</p>
  <div class="path">Certified artifact: <?=DIGIOPS_CERTIFIED_ARTIFACT_ID?><br>Source: <?=htmlspecialchars(substr(DIGIOPS_CERTIFIED_SOURCE_SHA,0,12), ENT_QUOTES, 'UTF-8')?></div>
  <?php if ($error): ?><div class="notice error"><?=htmlspecialchars($error, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>
  <?php if ($success): ?><div class="notice success"><?=htmlspecialchars($success, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>
  <?php if (!$success): ?>
  <form method="post" autocomplete="off">
    <input type="hidden" name="csrf" value="<?=htmlspecialchars((string)$_SESSION['csrf'], ENT_QUOTES, 'UTF-8')?>">
    <label for="token">GitHub fine-grained token</label>
    <input id="token" name="token" type="password" required autocomplete="off" placeholder="github_pat_…">
    <p class="muted">Required only for this bootstrap because the repository is private. The installer does not save this token.</p>
    <button type="submit"><?= $installed ? 'Reinstall latest certified release' : 'Install DigiOps' ?></button>
  </form>
  <?php endif; ?>
  <div class="path">Public: public_html/digiops/<br>Private: private_html/digiops/</div>
</div>
</body>
</html>
