<?php

namespace App\Registrars\DTO;

readonly class RemoteDomain
{
    /**
     * @param  list<string>  $nameservers
     */
    public function __construct(
        public string $name,
        public array $nameservers,
        public ?string $status = null,
        public ?string $tld = null,
        public ?float $renewalPrice = null,
        public ?string $registeredAt = null,
        public ?string $expiresAt = null,
        public ?bool $isLocked = null,
        public ?bool $privacyEnabled = null,
        public ?bool $autoRenew = null,
    ) {}
}
