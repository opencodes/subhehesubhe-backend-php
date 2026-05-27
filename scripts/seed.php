<?php

declare(strict_types=1);

use App\Config\AppContext;
use Dotenv\Dotenv;
use MongoDB\BSON\UTCDateTime;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
if (is_readable($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

AppContext::boot();
$mongo = AppContext::boot()->mongo;

function loadJson(string $file): array
{
    if (!is_readable($file)) {
        return [];
    }
    $decoded = json_decode((string) file_get_contents($file), true);
    return is_array($decoded) ? $decoded : [];
}

function seedCollection(\App\Services\MongoService $mongo, string $name, array $docs, callable $mapper): int
{
    $col = $mongo->collection($name);
    $col->deleteMany([]);
    $count = 0;
    foreach ($docs as $doc) {
        $mapped = $mapper($doc);
        if ($mapped !== null) {
            $col->insertOne($mapped);
            $count++;
        }
    }
    return $count;
}

$seedDir = $root . '/data/seed';
$restaurants = loadJson($seedDir . '/restaurants.json');
$vendors = loadJson($seedDir . '/vendors.json');
$campaigns = loadJson($seedDir . '/campaigns.json');

$restaurantCount = seedCollection($mongo, 'restaurants', $restaurants, static function (array $doc) {
    $id = (string) ($doc['id'] ?? '');
    if ($id === '') {
        return null;
    }
    unset($doc['id']);
    $doc['listingId'] = $id;
    $doc['createdAt'] = new UTCDateTime();
    return $doc;
});

$vendorCount = seedCollection($mongo, 'vendors', $vendors, static function (array $doc) {
    $id = (string) ($doc['id'] ?? '');
    if ($id === '') {
        return null;
    }
    unset($doc['id']);
    $doc['listingId'] = $id;
    $doc['createdAt'] = new UTCDateTime();
    return $doc;
});

$campaignCount = seedCollection($mongo, 'campaigns', $campaigns, static function (array $doc) {
    $id = (string) ($doc['id'] ?? '');
    if ($id === '') {
        return null;
    }
    unset($doc['id']);
    $doc['campaignId'] = $id;
    return $doc;
});

// Sample vendor enquiries for default vendor vn-4
$mongo->collection('vendor_enquiries')->deleteMany([]);
$mongo->collection('vendor_enquiries')->insertMany([
    [
        'id' => 'ENQ-2401',
        'vendorId' => 'vn-4',
        'guestName' => 'Priya & Arjun',
        'eventType' => 'Wedding reception',
        'eventDate' => '2026-11-14',
        'guests' => '250–300',
        'status' => 'New',
        'receivedAt' => '2 hours ago',
        'message' => 'Looking for lawn + catering package. Prefer vegetarian menu with live chaat counter.',
        'createdAt' => new UTCDateTime(),
    ],
    [
        'id' => 'ENQ-2398',
        'vendorId' => 'vn-4',
        'guestName' => 'Mehta family',
        'eventType' => 'Sangeet + wedding',
        'eventDate' => '2026-12-02',
        'guests' => '400+',
        'status' => 'Replied',
        'receivedAt' => 'Yesterday',
        'message' => 'Need full-day venue with mandap setup.',
        'createdAt' => new UTCDateTime(),
    ],
]);

echo "Seeded restaurants: {$restaurantCount}\n";
echo "Seeded vendors: {$vendorCount}\n";
echo "Seeded campaigns: {$campaignCount}\n";
echo "Seeded vendor enquiries: 2\n";
echo "Done.\n";
