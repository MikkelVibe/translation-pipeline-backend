<?php

namespace App\Enums;

enum JobItemStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Done = 'done';
    case Error = 'error';
}
