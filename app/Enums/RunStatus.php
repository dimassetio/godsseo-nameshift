<?php

namespace App\Enums;

enum RunStatus: string
{
    case Queued = 'QUEUED';
    case Running = 'RUNNING';
    case Succeeded = 'SUCCEEDED';
    case Failed = 'FAILED';
}
