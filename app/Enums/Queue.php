<?php

namespace App\Enums;

enum Queue: string
{
    case ProductFetch = 'product_fetch_queue';
    case ProductTranslate = 'product_translate_queue';
}
