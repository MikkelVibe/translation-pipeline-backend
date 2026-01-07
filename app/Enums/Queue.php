<?php

namespace App\Enums;

enum Queue: string
{
    case ProductFetch = 'product_fetch_queue';
    case ProductTranslate = 'product_translate_queue';
    case ProductQE = 'product_qe_queue';
    case ProductTranslationPersist = 'product_translation_persist_queue';
}

// New Queues are added like this, matching the string value to rabbitmq's config. (config/rabbitmq.php) 
// Value must correspond to the key of the corresponding queue in the config's "queues"-array.