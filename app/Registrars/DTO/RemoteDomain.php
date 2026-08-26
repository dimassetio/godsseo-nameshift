<?php

namespace App\Registrars\DTO;

readonly class RemoteDomain
{
    public function __construct(public string $name, public array $nameservers, public ?string $status = null) {}
}
