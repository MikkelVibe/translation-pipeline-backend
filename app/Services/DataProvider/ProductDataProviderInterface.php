<?php

namespace app\Services\DataProvider;

use App\DTOs\ProductDataDto;
use Illuminate\Support\Collection;

interface ProductDataProviderInterface
{
    /**
     * Get the total count of products available.
     */
    public function getTotalCount(): int;

    /**
     * Fetch collection of products for processing.
     *
     * @param  int  $limit  Determines how many per batch
     * @param  int  $offset  Determines which page you're on
     * @return Collection<int, ProductDataDto>
     */
    public function fetchProducts(int $limit = 100, int $offset = 0): Collection;

    /**
     * Fetch products by IDs.
     *
     * @param  array<string>  $ids
     * @return Collection<int, ProductDataDto>
     */
    public function fetchProductsByIds(array $ids): Collection;
}
