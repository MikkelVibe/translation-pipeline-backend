<?php

namespace App\Services\DataProvider;

use App\DTOs\ProductDataDto;
use Illuminate\Support\Collection;

class DummyProductDataProvider implements ProductDataProviderInterface
{
    public function fetchProducts(int $limit = 100, int $offset = 0): Collection
    {
        return collect(range(1, $limit))->map(fn ($i) => new ProductDataDto(
            id: (string) $i,
            title: "Dummy Product {$i}",
            description: "This is a dummy description for product {$i}.",
            metaTitle: "Buy Dummy Product {$i} - Best Price",
            metaDescription: "Dummy Product {$i} - High quality product with amazing features.",
            SEOKeywords: ["dummy", "product", "test", "keyword{$i}"],
        ));
    }

    public function fetchProductsByIds(array $ids): Collection
    {
        return collect($ids)->map(fn ($id) => new ProductDataDto(
            id: $id,
            title: "Dummy Product {$id}",
            description: "This is a dummy description for product {$id}.",
            metaTitle: "Buy Dummy Product {$id} - Best Price",
            metaDescription: "Dummy Product {$id} - High quality product with amazing features.",
            SEOKeywords: ["dummy", "product", "test", "keyword{$id}"],
        ));
    }
}
