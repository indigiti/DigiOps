<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use DigiOps\Security\Session;
use DigiOps\Support\JsonResponse;

Session::requireRole();

$buildFile = DIGIOPS_PRIVATE_ROOT . '/build/release.json';
$installFile = DIGIOPS_PRIVATE_ROOT . '/bootstrap-install.json';

$build = [];
$install = [];

if (is_file($buildFile)) {
    $decoded = json_decode((string)file_get_contents($buildFile), true);
    if (is_array($decoded)) $build = $decoded;
}
if (is_file($installFile)) {
    $decoded = json_decode((string)file_get_contents($installFile), true);
    if (is_array($decoded)) $install = $decoded;
}

$buildSha = (string)($build['sourceSha'] ?? '');
$installedSha = (string)($install['sourceSha'] ?? '');
$identityVerified = $buildSha !== '' && $buildSha !== 'local' && $installedSha !== '' && hash_equals($buildSha, $installedSha);

$assets = [];
$assetDir = DIGIOPS_PUBLIC_ROOT . '/assets';
if (is_dir($assetDir)) {
    foreach (array_values(array_diff(scandir($assetDir) ?: [], ['.','..'])) as $name) {
        if (is_file($assetDir . '/' . $name)) $assets[] = $name;
    }
}

JsonResponse::send([
    'ok'=>true,
    'runtime'=>[
        'name'=>(string)($build['name'] ?? 'DigiOps'),
        'version'=>(string)($build['version'] ?? 'unknown'),
        'sourceSha'=>$buildSha ?: $installedSha,
        'sourceShort'=>substr($buildSha ?: $installedSha,0,12),
        'branch'=>(string)($build['branch'] ?? ''),
        'builtAt'=>$build['builtAt'] ?? null,
        'ciRunNumber'=>$build['ciRunNumber'] ?? null,
        'ciRunId'=>$build['ciRunId'] ?? null,
        'ciRunAttempt'=>$build['ciRunAttempt'] ?? null,
        'artifactId'=>$install['artifactId'] ?? null,
        'artifactCreatedAt'=>$install['artifactCreatedAt'] ?? null,
        'installedAt'=>$install['installedAt'] ?? null,
        'phpVersion'=>PHP_VERSION,
        'serverApi'=>PHP_SAPI,
        'identityVerified'=>$identityVerified,
        'buildManifestPresent'=>is_file($buildFile),
        'installManifestPresent'=>is_file($installFile),
        'assetCount'=>count($assets),
        'assets'=>$assets,
    ],
]);

