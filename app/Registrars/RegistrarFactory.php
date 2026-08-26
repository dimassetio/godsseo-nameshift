<?php

namespace App\Registrars;

use App\Enums\RegistrarProvider;
use App\Models\RegistrarAccount;
use App\Registrars\Contracts\Registrar;

class RegistrarFactory
{
    public function for(RegistrarAccount $account): Registrar
    {
        return match ($account->provider) {
            RegistrarProvider::Namecheap => new NamecheapRegistrar($account),
            RegistrarProvider::NameCom => new NameComRegistrar($account),
        };
    }
}
