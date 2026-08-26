<?php

namespace App\Enums;

enum BulkChangeType: string
{
    case Change = 'CHANGE';
    case Import = 'IMPORT';
    case Retry = 'RETRY';
    case Rollback = 'ROLLBACK';
}
