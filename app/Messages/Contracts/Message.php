<?php

namespace App\Messages\Contracts;

use App\Enums\Queue;

interface Message
{
    /**
     * Convert the message to an array for publishing.
     * @return array<string, mixed>
     */
    public function toArray(): array;

    /**
     * Create a message instance from an array.
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static;

    public static function queue(): Queue;
}
