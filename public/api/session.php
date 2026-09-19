<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use DigiOps\Security\Session;
use DigiOps\Security\UserStore;
use DigiOps\Support\JsonResponse;

$store = new UserStore();
$user = Session::user();
JsonResponse::send([
    'ok'=>true,
    'installed'=>$store->hasUsers(),
    'authenticated'=>$user !== null,
    'user'=>$user,
    'csrf'=>$user ? Session::csrf() : null,
]);
