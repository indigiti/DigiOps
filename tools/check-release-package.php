<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$release=$root.'/release';

$required=[
    $release.'/public/index.html'=>'PUBLIC_INDEX_MISSING',
    $release.'/public/.htaccess'=>'PUBLIC_HTACCESS_MISSING',
    $release.'/public/api/session.php'=>'PUBLIC_API_SESSION_MISSING',
    $release.'/private/app/php/bootstrap.php'=>'PRIVATE_BOOTSTRAP_MISSING',
    $release.'/private/build/release.json'=>'BUILD_MANIFEST_MISSING',
];

foreach($required as $file=>$error){
    if(!is_file($file)){
        fwrite(STDERR,$error.': '.$file.PHP_EOL);
        exit(1);
    }
}

$ht=(string)file_get_contents($release.'/public/.htaccess');
foreach([
    'RewriteEngine On',
    'RewriteBase /digiops/',
    'RewriteRule ^api/ - [L]',
    'RewriteRule ^ index.html [L]',
] as $needle){
    if(!str_contains($ht,$needle)){
        fwrite(STDERR,'HTACCESS_RULE_MISSING: '.$needle.PHP_EOL);
        exit(1);
    }
}

$index=(string)file_get_contents($release.'/public/index.html');
if(!preg_match('#(?:src|href)="/digiops/(?:assets|favicon)#',$index)){
    fwrite(STDERR,'ABSOLUTE_DIGIOPS_ASSET_BASE_MISSING'.PHP_EOL);
    exit(1);
}

$manifest=json_decode((string)file_get_contents($release.'/private/build/release.json'),true);
if(!is_array($manifest)||($manifest['name']??'')!=='DigiOps'||empty($manifest['version'])){
    fwrite(STDERR,'BUILD_MANIFEST_INVALID'.PHP_EOL);
    exit(1);
}

echo "Release artifact verification: PASS".PHP_EOL;
