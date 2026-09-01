<?php

namespace App\Registrars\Contracts;

interface ProvidesRenewalPrices
{
    /**
     * @param  list<string>  $tlds
     * @return array<string, float>
     */
    public function renewalPrices(array $tlds): array;
}
