<?php

namespace App\Messages;

use App\DTOs\ProductDataDto;
use App\Enums\Queue;
use App\Messages\Contracts\Message;

class TranslationMessage implements Message
{
    public function __construct(
        public readonly int $jobItemId,
        public readonly string $externalId,
        public readonly ?string $title,
        public readonly ?string $description,
        public readonly ?string $metaTitle,
        public readonly ?string $metaDescription,
        public readonly ?array $seoKeywords,
    ) {}

    // Create a message from a ProductDataDto and job item ID.
    public static function fromProductData(int $jobItemId, ProductDataDto $product): self
    {
        return new self(
            jobItemId: $jobItemId,
            externalId: $product->id,
            title: $product->title,
            description: $product->description,
            metaTitle: $product->metaTitle,
            metaDescription: $product->metaDescription,
            seoKeywords: $product->SEOKeywords,
        );
    }

    public function toSourceTextArray(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'metaTitle' => $this->metaTitle,
            'metaDescription' => $this->metaDescription,
            'SEOKeywords' => $this->seoKeywords,
        ];
    }

    public function toArray(): array
    {
        return [
            'job_item_id' => $this->jobItemId,
            'id' => $this->externalId,
            'title' => $this->title,
            'description' => $this->description,
            'metaTitle' => $this->metaTitle,
            'metaDescription' => $this->metaDescription,
            'SEOKeywords' => $this->seoKeywords,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new self(
            jobItemId: $data['job_item_id'],
            externalId: $data['id'],
            title: $data['title'] ?? null,
            description: $data['description'] ?? null,
            metaTitle: $data['metaTitle'] ?? null,
            metaDescription: $data['metaDescription'] ?? null,
            seoKeywords: $data['SEOKeywords'] ?? null,
        );
    }

    public static function queue(): Queue
    {
        return Queue::ProductTranslate;
    }
}
