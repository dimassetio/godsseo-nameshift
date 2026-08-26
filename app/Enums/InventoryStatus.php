<?php

namespace App\Enums;

enum InventoryStatus: string
{
    case Available = 'AVAILABLE';
    case Unavailable = 'UNAVAILABLE';
    case Stale = 'STALE';
}
