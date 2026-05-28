<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\AppContext;
use App\Utils\ApiException;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;

final class VendorCategoryService
{
    /** @var list<array{id: string, name: string}> */
    private const DEFAULT_CATEGORIES = [
        ['id' => 'venues', 'name' => 'Venues'],
        ['id' => 'photographers', 'name' => 'Photographers'],
        ['id' => 'makeup', 'name' => 'Makeup Artists'],
        ['id' => 'planning-decor', 'name' => 'Planning & Decor'],
        ['id' => 'virtual-planning', 'name' => 'Virtual Planning'],
        ['id' => 'mehndi', 'name' => 'Mehndi'],
        ['id' => 'music-dance', 'name' => 'Sangeet & Choreographers'],
        ['id' => 'invites-gifts', 'name' => 'Invites & Gifts'],
        ['id' => 'food', 'name' => 'Catering'],
        ['id' => 'pre-wedding-shoot', 'name' => 'Pre Wedding Shoot'],
        ['id' => 'bridal-wear', 'name' => 'Bridal Wear'],
        ['id' => 'groom-wear', 'name' => 'Groom Wear'],
        ['id' => 'jewellery-accessories', 'name' => 'Jewellery'],
        ['id' => 'pandits', 'name' => 'Pandits'],
        ['id' => 'bridal-grooming', 'name' => 'Bridal Grooming'],
    ];

    /** @return list<array{id: string, name: string}> */
    public function list(): array
    {
        $collection = $this->collection();
        if ($collection->countDocuments([]) === 0) {
            $this->seedDefaults($collection);
        }

        $items = [];
        $cursor = $collection->find([], ['sort' => ['sortOrder' => 1, 'name' => 1]]);
        foreach ($cursor as $doc) {
            $items[] = [
                'id' => (string) ($doc['id'] ?? ''),
                'name' => (string) ($doc['name'] ?? ''),
            ];
        }

        return $items;
    }

    /** @return array{id: string, name: string} */
    public function create(string $id, string $name): array
    {
        $id = $this->normalizeId($id);
        $name = trim($name);
        if ($name === '') {
            throw new ApiException('Category name is required', 422);
        }

        $collection = $this->collection();
        if ($collection->findOne(['id' => $id]) !== null) {
            throw new ApiException('Category id already exists', 409);
        }

        $sortOrder = (int) $collection->countDocuments([]) + 1;
        $doc = [
            'id' => $id,
            'name' => $name,
            'sortOrder' => $sortOrder,
            'createdAt' => new UTCDateTime(),
            'updatedAt' => new UTCDateTime(),
        ];
        $collection->insertOne($doc);

        return ['id' => $id, 'name' => $name];
    }

    /** @return array{id: string, name: string} */
    public function update(string $id, string $name): array
    {
        $id = $this->normalizeId($id);
        $name = trim($name);
        if ($name === '') {
            throw new ApiException('Category name is required', 422);
        }

        $result = $this->collection()->updateOne(
            ['id' => $id],
            ['$set' => ['name' => $name, 'updatedAt' => new UTCDateTime()]]
        );

        if ($result->getMatchedCount() === 0) {
            throw new ApiException('Category not found', 404);
        }

        return ['id' => $id, 'name' => $name];
    }

    public function delete(string $id): void
    {
        $id = $this->normalizeId($id);
        $result = $this->collection()->deleteOne(['id' => $id]);
        if ($result->getDeletedCount() === 0) {
            throw new ApiException('Category not found', 404);
        }
    }

    private function collection(): Collection
    {
        return AppContext::boot()->mongo->collection('vendor_categories');
    }

    private function seedDefaults(Collection $collection): void
    {
        $order = 1;
        foreach (self::DEFAULT_CATEGORIES as $category) {
            $collection->insertOne([
                'id' => $category['id'],
                'name' => $category['name'],
                'sortOrder' => $order++,
                'createdAt' => new UTCDateTime(),
                'updatedAt' => new UTCDateTime(),
            ]);
        }
    }

    private function normalizeId(string $id): string
    {
        $id = strtolower(trim($id));
        if ($id === '' || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $id)) {
            throw new ApiException(
                'Category id must be lowercase letters, numbers, and hyphens only',
                422
            );
        }

        return $id;
    }
}
