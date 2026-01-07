<?php

namespace App\DTOs;

/**
 * Contract between TranslationWorker -> TranslationPersistWorker
 */
final class TranslationPersistMessageDto
{
   /**
    * @param array<string, string|array|null>|null $sourceText
    * @param array<string, string|array|null>|null $translatedText
    */
   public function __construct(
      public readonly int $jobItemId,
      public readonly ?string $externalId,
      public readonly ?array $sourceText,
      public readonly ?array $translatedText,
      public readonly ?int $jobId,
      public readonly ?int $languageId,
      public readonly ?string $errorMessage,
      public readonly ?string $errorStage,
   ) {}

   public static function fromArray(array $data): self
   {
      $jobItemId = $data['job_item_id'] ?? null;

      if (!\is_int($jobItemId) && !ctype_digit((string) $jobItemId)) {
         throw new \InvalidArgumentException('Missing or invalid job_item_id');
      }

      return new self(
         jobItemId: (int) $jobItemId,
         externalId: $data['external_id'] ?? null,
         sourceText: isset($data['source_text']) && \is_array($data['source_text'])
               ? $data['source_text']
               : null,
         translatedText: isset($data['translated_text']) && \is_array($data['translated_text'])
               ? $data['translated_text']
               : null,
         jobId: isset($data['job_id']) && ctype_digit((string) $data['job_id'])
               ? (int) $data['job_id']
               : null,
         languageId: isset($data['language_id']) && ctype_digit((string) $data['language_id'])
               ? (int) $data['language_id']
               : null,
         errorMessage: $data['error_message'] ?? null,
         errorStage: $data['error_stage'] ?? null
      );
   }

   public function isError(): bool
   {
      return !empty($this->errorMessage);
   }

   /**
    * @return array<string, mixed>
    */
   public function toArray(): array
   {
      return array_filter([
         'job_item_id' => $this->jobItemId,
         'external_id' => $this->externalId,
         'source_text' => $this->sourceText,
         'translated_text' => $this->translatedText,
         'job_id' => $this->jobId,
         'language_id' => $this->languageId,
         'error_message' => $this->errorMessage,
         'error_stage' => $this->errorStage,
      ], static fn ($v) => $v !== null);
   }
}
