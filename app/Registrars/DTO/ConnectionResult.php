<?php

namespace App\Registrars\DTO;

readonly class ConnectionResult
{
    public function __construct(public bool $successful, public string $message) {}
}
