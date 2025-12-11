<?php

namespace app\Services\DataProvider;

use App\DTOs\ProductDataDto;
use Illuminate\support\Collection;
use Illuminate\Support\Facades\Http;

class ShopwareProductDataProvider implements ProductDataProviderInterface
{
    public function __construct(
        private readonly string $baseUrl = '',
        private readonly string $token = '',
    ) {
      $this->baseUrl = $this->baseUrl ?: rtrim(config('services.shopware.url'),'/');
      $this->token = $this->token ?: (string) config('services.shopware.token');
    }

    public function fetchProducts(int $limit = 100, int $offset = 0): Collection
    {
      $url = "{$this->baseUrl}/api/product"; // Match med Shopware's api endpoint URIs for søgning af produkter
      $response = Http::withToken($this->token)
         ->post($url, [
            'page' => intdiv($offset, $limit) + 1,
            'limit' => $limit,
            'filter' => [], // senere kan filtre som "needs translation" tilføjes her (slet kommentar efter tilføjelse)
         ]);

      $data = $response->json('data') ?? []; // Nullish coalescing hvis ingen data --> nyt array (validerer)

      return collect($data)
         ->map(fn (array $raw) => $this->mapToProductDataDto($raw))
         ->filter();
    }

    public function fetchProductById(string $id): ?ProductDataDto
    {
      $url = "{$this->baseUrl}/api/product/{$id}"; 
      $response = Http::withToken($this->token)
         ->get($url);

         if (! $response->successful()) {
            return null;
         }

      $raw = $response->json();

      return $this->mapToProductDataDto($raw);
   }

   private function mapToProductDataDto(array $raw): ?ProductDataDto {
      $description = $raw['translated']['description'] ?? null;

      if ($description === null || trim($description) === '') {
         logger()->warning('Product skipped due to missing description', [
            'product_id' => $raw['id'] ?? null,
         ]);

         return null;
      }

      return new ProductDataDto(
         id: (string) ($raw['id'] ?? ''),
         sku: (string) ($raw['productNumber'] ?? ''),
         name: (string) ($raw['translated']['name'] ?? ''),
         description: $description,
      );
    }
}
