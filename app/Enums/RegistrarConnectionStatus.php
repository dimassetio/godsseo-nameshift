<?php

namespace App\Enums;

enum RegistrarConnectionStatus: string
{
    case Queued = 'QUEUED';
    case Running = 'RUNNING';
    case Succeeded = 'SUCCEEDED';
    case Failed = 'FAILED';
    case ActionRequired = 'ACTION_REQUIRED';
    case Cancelled = 'CANCELLED';
}
