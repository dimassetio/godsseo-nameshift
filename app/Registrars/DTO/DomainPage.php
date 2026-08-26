<?php

namespace App\Registrars\DTO;

readonly class DomainPage
{
    /** @param list<RemoteDomain> $domains */
    public function __construct(public array $domains, public ?int $nextPage) {}
}
