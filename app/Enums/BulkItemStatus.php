<?php

namespace App\Enums;

enum BulkItemStatus: string
{
    case Pending = 'PENDING';
    case Processing = 'PROCESSING';
    case Retrying = 'RETRYING';
    case Succeeded = 'SUCCEEDED';
    case Skipped = 'SKIPPED';
    case Conflict = 'CONFLICT';
    case Failed = 'FAILED';
    case Cancelled = 'CANCELLED';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Succeeded, self::Skipped, self::Conflict, self::Failed, self::Cancelled], true);
    }
}
