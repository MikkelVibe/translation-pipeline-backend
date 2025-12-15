<?php

namespace App\DTOs;

class ProductDataDto
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

}
