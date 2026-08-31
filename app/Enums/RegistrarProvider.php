<?php

namespace App\Enums;

enum RegistrarProvider: string
{
    case Namecheap = 'NAMECHEAP';
    case NameCom = 'NAMECOM';
    case NameSilo = 'NAMESILO';
    case Dynadot = 'DYNADOT';
    case Porkbun = 'PORKBUN';
    case Spaceship = 'SPACESHIP';
    case Infomaniak = 'INFOMANIAK';
    case ZCom = 'ZCOM';
}
