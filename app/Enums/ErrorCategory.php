<?php

namespace App\Enums;

enum ErrorCategory: string
{
    case Authentication = 'AUTHENTICATION';
    case Permission = 'PERMISSION';
    case Validation = 'VALIDATION';
    case DomainNotFound = 'DOMAIN_NOT_FOUND';
    case DomainNotOwned = 'DOMAIN_NOT_OWNED';
    case Conflict = 'CONFLICT';
    case RateLimit = 'RATE_LIMIT';
    case Network = 'NETWORK';
    case ProviderTemporary = 'PROVIDER_TEMPORARY';
    case ProviderPermanent = 'PROVIDER_PERMANENT';
    case Unknown = 'UNKNOWN';

    public function retryable(): bool
    {
        return in_array($this, [self::RateLimit, self::Network, self::ProviderTemporary], true);
    }
}
