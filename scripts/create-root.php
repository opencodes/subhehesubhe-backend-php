<?php

declare(strict_types=1);

use App\Config\AppContext;
use App\Config\Env;
use Dotenv\Dotenv;
use MongoDB\BSON\UTCDateTime;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
if (is_readable($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

$ctx = AppContext::boot();
$collection = $ctx->mongo->collection('platform_accounts');

// Read CLI arguments or fall back to .env values (or defaults)
$username = strtolower(trim($argv[1] ?? Env::string('ROOT_USERNAME', 'root')));
$password = $argv[2] ?? Env::string('ROOT_PASSWORD', 'change-me-root-password');

if ($username === '') {
    echo "Error: Username cannot be empty.\n";
    exit(1);
}

if ($password === '') {
    echo "Error: Password cannot be empty. Set ROOT_PASSWORD in .env or pass it as the second argument.\n";
    exit(1);
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);
if ($passwordHash === false) {
    echo "Error: Could not hash password.\n";
    exit(1);
}

try {
    $existing = $collection->findOne(['username' => $username, 'role' => 'root']);

    if ($existing !== null) {
        $collection->updateOne(
            ['_id' => $existing['_id']],
            ['$set' => [
                'passwordHash' => $passwordHash,
                'updatedAt' => new UTCDateTime(),
            ]]
        );
        echo "Root user '{$username}' already exists. Password successfully updated.\n";
    } else {
        $collection->insertOne([
            'username' => $username,
            'passwordHash' => $passwordHash,
            'role' => 'root',
            'name' => 'Root Administrator',
            'email' => '',
            'active' => true,
            'createdBy' => null,
            'createdAt' => new UTCDateTime(),
            'updatedAt' => new UTCDateTime(),
        ]);
        echo "Root user '{$username}' created successfully.\n";
    }
} catch (\Throwable $e) {
    echo "Database Error: Could not connect or insert into MongoDB.\n";
    echo "Details: " . $e->getMessage() . "\n";
    exit(1);
}
