<?php

namespace App\Services\Translation;

use App\Models\Job;

interface TranslatorInterface
{
    /**
     * Translate content using the job's configured prompt and languages.
     *
     * @param  array<string, string|array|null>  $content  The content to translate (title, description, etc.)
     * @param  Job  $job  The job containing prompt and language configuration
     * @return array<string, string|array|null> The translated content with same structure
     */
    public function translate(array $content, Job $job): array;
}
