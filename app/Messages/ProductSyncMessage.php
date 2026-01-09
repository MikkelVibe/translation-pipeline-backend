<?php

namespace App\Messages;

use App\Enums\Queue;
use App\Messages\Contracts\Message;

class ProductSyncMessage implements Message
{
    public function __construct(
        public readonly string $type,
        public readonly int $jobId,
        public readonly ?array $ids = null,
        public readonly ?int $startPage = null,
        public readonly ?int $endPage = null,
        public readonly ?int $limit = null,
    ) {}

    /**
     * Create a message for fetching products by IDs.
     *
     * @param  array<int, string>  $ids
     */
    public static function forIds(int $jobId, array $ids): self
    {
        return new self(
            type: 'ids',
            jobId: $jobId,
            ids: $ids,
        );
    }

    /**
     * Create a message for fetching products by page range.
     */
    public static function forRange(int $jobId, int $startPage, int $endPage, int $limit): self
    {
        return new self(
            type: 'range',
            jobId: $jobId,
            startPage: $startPage,
            endPage: $endPage,
            limit: $limit,
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

    public function toArray(): array
    {
        $data = [
            'type' => $this->type,
            'job_id' => $this->jobId,
        ];

        if ($this->isIdsType()) {
            $data['ids'] = $this->ids;
        } else {
            $data['start_page'] = $this->startPage;
            $data['end_page'] = $this->endPage;
            $data['limit'] = $this->limit;
        }

        return $data;
    }

    public static function fromArray(array $data): static
    {
        return new self(
            type: $data['type'],
            jobId: $data['job_id'],
            ids: $data['ids'] ?? null,
            startPage: $data['start_page'] ?? null,
            endPage: $data['end_page'] ?? null,
            limit: $data['limit'] ?? null,
        );
    }

    public static function queue(): Queue
    {
        return Queue::ProductFetch;
    }
}
