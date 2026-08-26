<?php

namespace App\Registrars\Exceptions;

use App\Enums\ErrorCategory;
use RuntimeException;

class ProviderException extends RuntimeException
{
    public function __construct(
        public readonly ErrorCategory $category,
        string $message,
        public readonly ?string $providerCode = null,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    public function retryable(): bool
    {
        return $this->category->retryable();
    }
}
