<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Database;

final class MongoService
{
    private ?Client $client = null;

    public function client(): Client
    {
        if ($this->client === null) {
            $this->client = new Client(Env::string('MONGODB_URI', 'mongodb://127.0.0.1:27017'));
        }

        return $this->client;
    }

    public function db(): Database
    {
        return $this->client()->selectDatabase(Env::string('MONGODB_DATABASE', 'shubhesubhe'));
    }

    public function collection(string $name): Collection
    {
        return $this->db()->selectCollection($name);
    }
}
