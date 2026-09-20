<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/php/bootstrap.php';

use DigiOps\GitHub\DeploymentCandidateResolver;

$runs = [
    ['id'=>10,'run_number'=>10,'name'=>'Stage Certification','status'=>'completed','conclusion'=>'success','head_sha'=>'aaa'],
    ['id'=>11,'run_number'=>11,'name'=>'SYN-DI Certification','status'=>'completed','conclusion'=>'success','head_sha'=>'bbb'],
    ['id'=>12,'run_number'=>12,'name'=>'Older Build','status'=>'completed','conclusion'=>'success','head_sha'=>'ccc'],
];
$fixtures = [
    10 => ['artifacts'=>[]],
    11 => ['artifacts'=>[
        ['id'=>99,'name'=>'digiops-release','expired'=>false],
    ]],
    12 => ['artifacts'=>[
        ['id'=>98,'name'=>'other','expired'=>false],
    ]],
];

$result = DeploymentCandidateResolver::resolve($runs, 'digiops-release', static fn(int $id): array => $fixtures[$id] ?? []);
if (($result['run']['id'] ?? null) !== 11) throw new RuntimeException('WRONG_RUN_SELECTED');
if (($result['artifact']['id'] ?? null) !== 99) throw new RuntimeException('WRONG_ARTIFACT_SELECTED');
if (($result['match'] ?? '') !== 'configured-name') throw new RuntimeException('WRONG_MATCH_MODE');
if (($result['reason'] ?? '') !== 'ready') throw new RuntimeException('WRONG_REASON');

$fallback = DeploymentCandidateResolver::resolve(
    [['id'=>20,'run_number'=>20,'name'=>'Build','status'=>'completed','conclusion'=>'success','head_sha'=>'ddd']],
    'configured-name',
    static fn(int $id): array => ['artifacts'=>[['id'=>77,'name'=>'only-artifact','expired'=>false]]]
);
if (($fallback['artifact']['id'] ?? null) !== 77) throw new RuntimeException('FALLBACK_FAILED');
if (($fallback['match'] ?? '') !== 'single-artifact-fallback') throw new RuntimeException('FALLBACK_MODE_FAILED');

echo "Deployment candidate resolver: PASS\n";
