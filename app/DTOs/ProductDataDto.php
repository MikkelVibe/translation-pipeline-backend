<?php

namespace App\DTOs;

class ProductDataDto {
   public function __construct(
      public readonly string $id,
      public readonly string $sku,
      public readonly string $name,
      public readonly ?string $description,
   ) {}
}