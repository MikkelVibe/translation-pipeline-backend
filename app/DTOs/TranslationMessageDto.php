<?php

namespace App\DTOs;

final class TranslationMessageDto
{
    public function __construct(
        public readonly int $jobItemId,
        public readonly string $externalId,
        public readonly ?string $title,
        public readonly ?string $description,
        public readonly ?string $metaTitle,
        public readonly ?string $metaDescription,
        public readonly ?array $SEOKeywords
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            jobItemId: $data['job_item_id'],
            externalId: $data['id'],
            title: $data['title'] ?? null,
            description: $data['description'] ?? null,
            metaTitle: $data['metaTitle'] ?? null,
            metaDescription: $data['metaDescription'] ?? null,
            SEOKeywords: $data['SEOKeywords'] ?? null
        );
    }

    public function toSourceTextArray(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'metaTitle' => $this->metaTitle,
            'metaDescription' => $this->metaDescription,
            'SEOKeywords' => $this->SEOKeywords,
        ];
    }
}
