<?php

namespace App\Services\DataProvider;

use App\DTOs\ProductDataDto;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class ShopwareProductDataProvider implements ProductDataProviderInterface
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
    ) {}

    public function getTotalCount(): int
    {
        // TODO: Remove hardcoded limit for testing
        return 10;

        $url = "{$this->baseUrl}/store-api/product";

        $response = Http::withHeaders([
            'sw-access-key' => $this->token,
            'Content-Type' => 'application/json',
        ])
            ->post($url, [
                'page' => 1,
                'limit' => 1,
                'total-count-mode' => 'exact',
            ]);

        if (!$response->successful()) {
            logger()->error('Failed to fetch product count', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return 0;
        }

        return (int) ($response->json('total') ?? 0);
    }

    public function fetchProducts(int $limit = 100, int $offset = 0): Collection
    {
        $url = "{$this->baseUrl}/store-api/product";

        $page = intdiv($offset, $limit) + 1;

        // TODO: Remove hardcoded limit for testing
        $limit = min($limit, 10);

        $response = Http::withHeaders([
            'sw-access-key' => $this->token,
            'Content-Type' => 'application/json',
        ])
            ->post($url, [
                'page' => $page,
                'limit' => $limit,
                'total-count-mode' => 'none',
            ]);

        if (!$response->successful()) {
            logger()->error('Failed to fetch products', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return collect();
        }

        $data = $response->json('elements') ?? [];

        return collect($data)
            ->map(fn (array $raw) => $this->mapToProductDataDto($raw))
            ->filter();
    }

    public function fetchProductsByIds(array $ids): Collection
    {
        if (empty($ids)) {
            return collect();
        }

        $url = "{$this->baseUrl}/store-api/product";

        $response = Http::withHeaders([
            'sw-access-key' => $this->token,
            'Content-Type' => 'application/json',
        ])
            ->post($url, [
                'ids' => $ids,
                'limit' => count($ids),
            ]);

        if (!$response->successful()) {
            echo "[SHOPWARE] Failed to fetch products by IDs. Status: {$response->status()}\n";
            logger()->error('Failed to fetch products by IDs', [
                'status' => $response->status(),
                'body' => $response->body(),
                'ids' => $ids,
            ]);

            return collect();
        }

        $data = $response->json('elements') ?? [];

        return collect($data)
            ->map(fn (array $raw) => $this->mapToProductDataDto($raw))
            ->filter();
    }

    private function mapToProductDataDto(array $raw): ?ProductDataDto
    {
        $title = $raw['translated']['name'] ?? $raw['name'] ?? null;

        $description = $raw['translated']['description']
            ?? $raw['description']
            ?? null;

        $metaTitle = $raw['translated']['metaTitle']
            ?? $raw['metaTitle']
            ?? null;

        $metaDescription = $raw['translated']['metaDescription']
            ?? $raw['metaDescription']
            ?? null;

        $keywords = $raw['translated']['keywords']
            ?? $raw['keywords']
            ?? null;

        $seoKeywords = [];
        if ($keywords !== null && trim($keywords) !== '') {
            $seoKeywords = array_map('trim', explode(',', $keywords));
        }

        // Skip if no title
        if ($title === null || trim($title) === '') {
            return null;
        }

        return new ProductDataDto(
            id: (string) ($raw['id'] ?? ''),
            title: $title,
            description: $description,
            metaTitle: $metaTitle,
            metaDescription: $metaDescription,
            SEOKeywords: $seoKeywords,
        );
    }
}
