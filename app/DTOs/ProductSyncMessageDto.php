<?php

namespace App\DTOs;

class ProductSyncMessageDto
{
    public function __construct(
        public readonly string $type,
        public readonly int $jobId,
        public readonly ?array $ids = null,
        public readonly ?int $startPage = null,
        public readonly ?int $endPage = null,
        public readonly ?int $limit = null
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            type: $data['type'],
            jobId: $data['job_id'],
            ids: $data['ids'] ?? null,
            startPage: $data['start_page'] ?? null,
            endPage: $data['end_page'] ?? null,
            limit: $data['limit'] ?? null
        );
    }

    public function isIdsType(): bool
    {
        return $this->type === 'ids';
    }

    public function isRangeType(): bool
    {
        return $this->type === 'range';
    }
}
