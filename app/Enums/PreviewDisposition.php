<?php

namespace App\Enums;

enum PreviewDisposition: string
{
    case Change = 'CHANGE';
    case WillSkip = 'WILL_SKIP';
    case Blocked = 'BLOCKED';
}
