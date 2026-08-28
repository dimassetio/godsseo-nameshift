<?php

namespace App\Registrars\Browser;

readonly class BrowserResult
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $storageState
     */
    public function __construct(
        public array $data,
        public ?array $storageState = null,
    ) {}
}
