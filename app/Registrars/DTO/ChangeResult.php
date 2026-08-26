<?php

namespace App\Registrars\DTO;

readonly class ChangeResult
{
    public function __construct(public bool $accepted, public ?string $providerCode = null) {}
}
