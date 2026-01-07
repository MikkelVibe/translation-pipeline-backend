<?php

namespace App\Services\Translation;

use App\Models\Job;

class MockTranslator implements TranslatorInterface
{
    public function translate(array $content, Job $job): array
    {
        $result = [];

        foreach ($content as $key => $value) {
            if ($value === null) {
                $result[$key] = null;
            } elseif (is_array($value)) {
                $result[$key] = array_map(fn ($v) => '[TRANSLATED] '.$v, $value);
            } else {
                $result[$key] = '[TRANSLATED] '.$value;
            }
        }

        return $result;
    }
}
