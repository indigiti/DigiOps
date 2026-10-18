<?php
declare(strict_types=1);

define('DIGIOPS_PRIVATE_ROOT', sys_get_temp_dir().'/digiops-test');
define('DIGIOPS_APP_HOME', sys_get_temp_dir());
define('DIGIOPS_SOURCE_ROOT', dirname(__DIR__,2));

spl_autoload_register(static function(string $class): void {
    $prefix='DigiOps\\';
    if(!str_starts_with($class,$prefix))return;
    $relative=str_replace('\\','/',substr($class,strlen($prefix)));
    $path=dirname(__DIR__,2).'/app/php/src/'.$relative.'.php';
    if(is_file($path))require_once $path;
});

use DigiOps\GitHub\DeploymentCandidateSelector;

$runs=[
 ['id'=>66,'status'=>'completed','conclusion'=>'success','run_number'=>66,'head_sha'=>'c4181cc'],
 ['id'=>453,'status'=>'completed','conclusion'=>'success','run_number'=>453,'head_sha'=>'c4181cc'],
];
$byRun=[
 66=>['artifacts'=>[['id'=>10625447347,'name'=>'qsyn-stage-chartos-cert','expired'=>false]]],
 453=>['artifacts'=>[['id'=>10625402101,'name'=>'digiops-release','expired'=>false]]],
];
$sel=DeploymentCandidateSelector::find($runs,fn(int $id): array=>$byRun[$id]??['artifacts'=>[]],'digiops-release');
assert(($sel['run']['run_number']??null)===453);
assert(($sel['artifact']['id']??null)===10625402101);
assert(($sel['match']??'')==='configured-name');
assert(($sel['successfulRunsChecked']??0)===2);

assert(DeploymentCandidateSelector::exactArtifact($byRun[66]['artifacts'],'digiops-release')===null);
assert((DeploymentCandidateSelector::exactArtifact($byRun[453]['artifacts'],'digiops-release',10625402101)['id']??0)===10625402101);
assert(DeploymentCandidateSelector::exactArtifact($byRun[453]['artifacts'],'digiops-release',10625447347)===null);

$stage=['branch'=>'main','environment'=>'Stage','artifactName'=>'digiops-release'];
$production=['branch'=>'main','environment'=>'Production'];
if (DeploymentCandidateSelector::candidateBranch($stage,'') !== 'main') throw new RuntimeException('Configured branch regression');
if (DeploymentCandidateSelector::candidateBranch($stage,'main') !== 'main') throw new RuntimeException('Source branch regression');
if (DeploymentCandidateSelector::candidateBranch($stage,'staging/vela-0.8.4-rc1') !== 'staging/vela-0.8.4-rc1') throw new RuntimeException('Stage branch not selected');
if (DeploymentCandidateSelector::candidateBranch($stage,'fix/staging-gaps') !== 'fix/staging-gaps') throw new RuntimeException('Supported staging-fix branch blocked');
DeploymentCandidateSelector::assertRunBranch(['head_branch'=>'staging/vela-0.8.4-rc1'],'staging/vela-0.8.4-rc1');

$expectFailure=static function(callable $fn,string $message) : void {
    try { $fn(); }
    catch (RuntimeException $e) { return; }
    throw new RuntimeException($message);
};
$expectFailure(fn()=>DeploymentCandidateSelector::candidateBranch($production,'staging/vela-0.8.4-rc1'),'Production override accepted');
$expectFailure(fn()=>DeploymentCandidateSelector::candidateBranch($stage,'feature/unreviewed'),'Unrestricted feature branch accepted');
$expectFailure(fn()=>DeploymentCandidateSelector::candidateBranch($stage,'staging/../main'),'Traversal accepted');
$expectFailure(fn()=>DeploymentCandidateSelector::candidateBranch($stage,'staging//bad'),'Duplicate slash accepted');
$expectFailure(fn()=>DeploymentCandidateSelector::candidateBranch($stage,'staging/bad/'),'Trailing slash accepted');
$expectFailure(fn()=>DeploymentCandidateSelector::assertRunBranch(['head_branch'=>'main'],'staging/vela-0.8.4-rc1'),'Run branch mismatch accepted');
$expectFailure(fn()=>DeploymentCandidateSelector::assertRunBranch(['head_branch'=>'staging/evil'],'staging/vela-0.8.4-rc1'),'Spoofed stage branch accepted');
if (DeploymentCandidateSelector::exactArtifact([
    ['id'=>54,'name'=>'digiops-release','expired'=>true]
],'digiops-release') !== null) throw new RuntimeException('Expired artifact was eligible');

echo "DeploymentCandidateSelectorTest PASS\n";
