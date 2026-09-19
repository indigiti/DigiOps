<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$dist=$root.'/dist';
if (!is_dir($dist)) { fwrite(STDERR,"dist missing; run npm build first\n"); exit(1); }
$out=$root.'/release';
$remove=function(string $path) use (&$remove): void {
    if (!file_exists($path)) return;
    if (is_file($path)||is_link($path)) { @unlink($path); return; }
    foreach (array_diff(scandir($path)?:[],['.','..']) as $name) $remove($path.'/'.$name);
    @rmdir($path);
};
$copy=function(string $src,string $dst) use (&$copy): void {
    if (is_dir($src)) { if(!is_dir($dst)) mkdir($dst,0755,true); foreach(array_diff(scandir($src)?:[],['.','..']) as $n)$copy($src.'/'.$n,$dst.'/'.$n); }
    else { if(!is_dir(dirname($dst)))mkdir(dirname($dst),0755,true); copy($src,$dst); }
};
$remove($out);mkdir($out,0755,true);
$copy($dist,$out.'/public');
$copy($root.'/app/php',$out.'/private/app/php');
file_put_contents($out.'/RELEASE.json',json_encode(['name'=>'DigiOps','version'=>'1.0.0','builtAt'=>date(DATE_ATOM),'public'=>'public','private'=>'private'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo "release built\n";
