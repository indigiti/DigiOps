<?php
declare(strict_types=1);

$root=sys_get_temp_dir().'/digiops-lifecycle-'.bin2hex(random_bytes(5));
define('DIGIOPS_PRIVATE_ROOT',$root.'/private');
define('DIGIOPS_APP_HOME',$root);
define('DIGIOPS_SOURCE_ROOT',dirname(__DIR__,2));

spl_autoload_register(static function(string $class): void {
    $prefix='DigiOps\\';
    if(!str_starts_with($class,$prefix))return;
    $path=dirname(__DIR__,2).'/app/php/src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
    if(is_file($path)) require_once $path;
});

use DigiOps\Registry\ProjectRegistry;
use DigiOps\Support\Files;

$registry=new ProjectRegistry($root.'/private/registry/projects.json');
$p=$registry->create([
    'id'=>'lexiconschoolgroup',
    'name'=>'Lexicon School Group',
    'repo'=>'ndigiti/Lexicon-SC-26',
    'branch'=>'main',
]);
if($p['repo']!=='indigiti/Lexicon-SC-26')throw new RuntimeException('LEXICON_REPO_NOT_CORRECTED');
if($p['suspended']!==false)throw new RuntimeException('DEFAULT_SUSPENSION_INVALID');

$registry->patchRuntime($p['id'],[
    'status'=>'deployed','commit'=>str_repeat('a',40),'release'=>'release-2026-11-05',
    'health'=>'healthy','update'=>true,'lastDeploy'=>'2026-11-05T10:00:00+00:00',
]);
$before=$registry->find($p['id']);
$changed=$registry->updateConfig($p['id'],[
    'name'=>'LexiconSchoolGroup',
    'repo'=>'indigiti/Lexicon-SC-26',
    'branch'=>'stable',
]);
foreach(['status','commit','release','health','lastDeploy','publicPath','privatePath'] as $field){
    if($changed[$field]!==$before[$field])throw new RuntimeException('EDIT_CORRUPTED_'.$field);
}
if($changed['update']!==false)throw new RuntimeException('EDIT_DID_NOT_INVALIDATE_GITHUB_CANDIDATE');

$suspended=$registry->setSuspended($p['id'],true);
if(!$suspended['suspended']||!$registry->find($p['id'])['suspended'])throw new RuntimeException('SUSPEND_NOT_SAVED');
$reactivated=$registry->setSuspended($p['id'],false);
if($reactivated['suspended'])throw new RuntimeException('REACTIVATION_FAILED');

foreach([
    fn()=> $registry->create(['id'=>$p['id'],'repo'=>'indigiti/other']),
    fn()=> $registry->updateConfig($p['id'],['id'=>'different']),
    fn()=> $registry->updateConfig($p['id'],['commit'=>'spoofed']),
    fn()=> $registry->updateConfig($p['id'],['targetId'=>'another-server']),
] as $invalid){
    try{$invalid();throw new RuntimeException('UNSAFE_CHANGE_ACCEPTED');}
    catch(\InvalidArgumentException $e){}
    catch(\RuntimeException $e){if($e->getMessage()==='UNSAFE_CHANGE_ACCEPTED')throw $e;}
}
$registry->delete($p['id']);
if($registry->find($p['id'])!==null)throw new RuntimeException('REMOVE_FAILED');
Files::removeTree($root);
echo "ProjectLifecycleTest PASS\n";
