<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/app/php/bootstrap.php';
use DigiOps\KrushiWorld\SetupManager;
function assertSetup(bool $ok,string $m):void{if(!$ok)throw new RuntimeException('SETUP_TEST_FAILED: '.$m);}
$storage=sys_get_temp_dir().'/kp-digiops-test-'.bin2hex(random_bytes(8));
mkdir($storage,0700,true);
$manager=new SetupManager($storage);
$s=$manager->status();
assertSetup(!$s['keyConfigured'],'initial unconfigured');
$s=$manager->configure(str_repeat('a',40));
assertSetup($s['mode']==='staging'&&$s['keyConfigured']&&$s['authKeyConfigured'],'configuration');
$file=$storage.'/config/runtime.json';
assertSetup(((fileperms($file)&0077)===0),'private permissions');
$c=json_decode(file_get_contents($file),true);
assertSetup($c['APMC_DATA_GOV_IN_API_KEY']===str_repeat('a',40),'private key persisted');
$secret=$c['APMC_AUTH_KEY'];
$manager->configure('');
assertSetup(json_decode(file_get_contents($file),true)['APMC_AUTH_KEY']===$secret,'encryption secret never rotated');
$grant=$manager->issueGrant();
assertSetup(strlen($grant)===64,'strong grant');
$g=json_decode(file_get_contents($storage.'/auth/web-bootstrap.json'),true);
assertSetup($g['used']===false && $g['sha256']===hash('sha256',$grant),'only hashed grant persisted');
assertSetup(!str_contains(file_get_contents($storage.'/auth/web-bootstrap.json'),$grant),'no plaintext grant at rest');
$rejected=false;try{$manager->configure('invalid');}catch(RuntimeException){$rejected=true;}
assertSetup($rejected,'bad key rejected');
$c['APMC_AUTH_MODE']='production';
file_put_contents($file,json_encode($c));chmod($file,0600);
$rejected=false;try{$manager->configure('');}catch(RuntimeException){$rejected=true;}
assertSetup($rejected,'production refuses staging downgrade');
@unlink($storage.'/auth/web-bootstrap.json');
@unlink($storage.'/auth/web-bootstrap.json.lock');
@unlink($file);@unlink($file.'.lock');
@rmdir($storage.'/auth');@rmdir($storage.'/config');@rmdir($storage);
echo "DigiOps KrushiWorld secure setup: PASS\n";
