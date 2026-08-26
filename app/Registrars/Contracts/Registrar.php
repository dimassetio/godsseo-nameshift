<?php

namespace App\Registrars\Contracts;

use App\Registrars\DTO\ChangeResult;
use App\Registrars\DTO\ConnectionResult;
use App\Registrars\DTO\DomainPage;

interface Registrar
{
    public function testConnection(): ConnectionResult;

    public function listDomains(int $page = 1): DomainPage;

    /** @return list<string> */
    public function getNameservers(string $domain): array;

    /** @param list<string> $nameservers */
    public function setNameservers(string $domain, array $nameservers): ChangeResult;
}
