<?php

namespace App\Enums;

enum BulkChangeStatus: string
{
    case Draft = 'DRAFT';
    case Queued = 'QUEUED';
    case Running = 'RUNNING';
    case Succeeded = 'SUCCEEDED';
    case PartiallySucceeded = 'PARTIALLY_SUCCEEDED';
    case Failed = 'FAILED';
    case Cancelled = 'CANCELLED';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Succeeded, self::PartiallySucceeded, self::Failed, self::Cancelled], true);
    }
}
