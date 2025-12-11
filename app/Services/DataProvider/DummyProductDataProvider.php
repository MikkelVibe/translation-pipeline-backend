<?php 

namespace app\Services\DataProvider;

use App\DTOs\ProductDataDto;
use Illuminate\support\Collection;
use Illuminate\Support\Facades\Http;

class DummyProductDataProvider implements ProductDataProviderInterface
{
    public function fetchProducts(int $limit = 100, int $offset = 0): Collection
    {
        return collect(range(1, $limit))->map(fn ($i) => new ProductDataDto(
            id: (string) $i,
            sku: "SKU-{$i}",
            name: "Dummy Product {$i}",
            description: "This is a dummy description for product {$i}.",
        ));
    }

    public function fetchProductById(string $id): ?ProductDataDto
    {
        return new ProductDataDto(
            id: $id,
            sku: "SKU-{$id}",
            name: "Dummy Product {$id}",
            description: "This is a dummy description for product {$id}.",
        );
    }
}
