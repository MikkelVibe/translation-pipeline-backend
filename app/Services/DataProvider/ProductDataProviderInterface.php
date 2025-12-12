<?php

namespace app\Services\DataProvider;

use App\DTOs\ProductDataDto;
use Illuminate\Support\Collection;

interface ProductDataProviderInterface
{
    /**
     * Fetch collection of products for processing
     *
     * @return Collection<int, ProductDataDto>
     */
    /**
     * Summary of fetchProducts, inputs purpose comes with pagination and/or batching
     *
     * @param  int  $limit  Determines how many per batch
     * @param  int  $offset  Determines which page youre on
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
