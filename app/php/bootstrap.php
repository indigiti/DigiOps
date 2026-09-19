<?php
declare(strict_types=1);

$sourceRoot = dirname(__DIR__, 2);

spl_autoload_register(static function (string $class) use ($sourceRoot): void {
    $prefix = 'DigiOps\\';
    if (!str_starts_with($class, $prefix)) return;
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $path = $sourceRoot . '/app/php/src/' . $relative . '.php';
    if (is_file($path)) require_once $path;
});

$documentRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: '';
$appHome = $documentRoot !== '' ? dirname($documentRoot) : dirname($sourceRoot);

define('DIGIOPS_SOURCE_ROOT', $sourceRoot);
define('DIGIOPS_PUBLIC_ROOT', $documentRoot);
define('DIGIOPS_APP_HOME', $appHome);
define(
    'DIGIOPS_PRIVATE_ROOT',
    rtrim((string)(getenv('DIGIOPS_PRIVATE_DIR') ?: ($appHome . '/private_html/digiops')), '/')
);

date_default_timezone_set((string)(getenv('APP_TIMEZONE') ?: 'Asia/Kolkata'));
