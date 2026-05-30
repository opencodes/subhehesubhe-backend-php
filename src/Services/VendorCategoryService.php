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
        ['id' => 'tent-furniture', 'name' => 'Tent & Furniture'],
        ['id' => 'catering-service', 'name' => 'Catering Service & Halwai'],
        ['id' => 'transportation', 'name' => 'Car Rental & Transportation'],
        ['id' => 'lighting', 'name' => 'Lighting'],
        ['id' => 'sound', 'name' => 'Sound'],
        ['id' => 'dhol-player', 'name' => 'Dhol Players'],
        ['id' => 'dj-performer', 'name' => 'DJ & Performers'],
        ['id' => 'decor', 'name' => 'Decoration'],
        ['id' => 'sweets-shop', 'name' => 'Sweets Shop'],
        ['id' => 'photographer-videographer', 'name' => 'Photographer & Videographer'],
        ['id' => 'makeup-artist', 'name' => 'Makeup Artist'],
        ['id' => 'milk-dairy', 'name' => 'Milk & Dairy'],
        ['id' => 'curd', 'name' => 'Curd'],
        ['id' => 'vegitable', 'name' => 'Vegitable'],
        ['id' => 'fruit', 'name' => 'Fruit'],
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

    /** @return array{id: string, name: string, category_img: string} */
    public function create(string $id, string $name, string $category_img): array
    {
        $id = $this->normalizeId($id);
        $name = trim($name);
        $category_img = trim($category_img);
        if ($name === '') {
            throw new ApiException('Category name is required', 422);
        }
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
            'category_img' => $category_img,
            'sortOrder' => $sortOrder,
            'createdAt' => new UTCDateTime(),
            'updatedAt' => new UTCDateTime(),
        ];
        $collection->insertOne($doc);
    
        return ['id' => $id, 'name' => $name, 'category_img' => $category_img];
    }

    /** @return array{id: string, name: string} */
    public function update(string $id, string $name , string $category_img): array
    {
        $id = $this->normalizeId($id);
        $name = trim($name);
        if ($name === '') {
            throw new ApiException('Category name is required', 422);
        }

        $result = $this->collection()->updateOne(
            ['id' => $id],
            ['$set' => ['name' => $name, 'category_img' => $category_img, 'updatedAt' => new UTCDateTime()]]
        );

        if ($result->getMatchedCount() === 0) {
            throw new ApiException('Category not found', 404);
        }

        return ['id' => $id, 'name' => $name, 'category_img' => $category_img];
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
