<?php

namespace App\DTOs;

use JsonSerializable;

class ProductDataDto implements JsonSerializable
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $title,
        public readonly ?string $description,

        public readonly ?string $metaTitle,
        public readonly ?string $metaDescription,

        /** @var string[] */
        public readonly array $SEOKeywords
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'metaTitle' => $this->metaTitle,
            'metaDescription' => $this->metaDescription,
            'SEOKeywords' => $this->SEOKeywords,
        ];
    }
}
