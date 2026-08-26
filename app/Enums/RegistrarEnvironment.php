<?php

namespace App\Enums;

enum RegistrarEnvironment: string
{
    case Sandbox = 'SANDBOX';
    case Production = 'PRODUCTION';
}
