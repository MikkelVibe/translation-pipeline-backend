<?php

namespace App\Enums;

enum Queue: string
{
    case ProductFetch = 'product_fetch';
    case ProductTranslate = 'product_translate';
    case ProductQE = 'product_qe';
}

// New Queues are added like this, matching the string value to rabbitmq's config. (config/rabbitmq.php) 
// Value must correspond to the key of the corresponding queue in the config's "queues"-array.
