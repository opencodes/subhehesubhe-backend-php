<?php

declare(strict_types=1);

use App\Bootstrap\AppFactory;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
if (is_readable($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

$app = AppFactory::create();
$app->run();
