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

use DigiOps\Security\PathGuard;

assert(PathGuard::slug('app-1')==='app-1');
assert(PathGuard::publicRelative('app-1')==='public_html/app-1/');
$failed=false;
try { PathGuard::assertManagedRelative('../etc/passwd'); } catch(Throwable) { $failed=true; }
assert($failed===true);
$failed=false;
try { PathGuard::slug('Bad Slug'); } catch(Throwable) { $failed=true; }
assert($failed===true);
echo "PathGuardTest PASS\n";
