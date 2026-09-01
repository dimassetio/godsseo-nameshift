<?php

namespace App\Registrars;

use App\Enums\ErrorCategory;
use App\Models\RegistrarAccount;
use App\Registrars\Contracts\Registrar;
use App\Registrars\DTO\ChangeResult;
use App\Registrars\DTO\ConnectionResult;
use App\Registrars\DTO\DomainPage;
use App\Registrars\DTO\RemoteDomain;
use App\Registrars\Exceptions\ProviderException;
use App\Services\NameserverSet;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class InfomaniakRegistrar implements Registrar
{
    public function __construct(private readonly RegistrarAccount $account) {}

    public function testConnection(): ConnectionResult
    {
        $this->request('/2/domains/domains', ['page' => 1]);

        return new ConnectionResult(true, 'Connection successful.');
    }

    public function listDomains(int $page = 1): DomainPage
    {
        $payload = $this->request('/2/domains/domains', ['page' => $page]);
        $data = $payload['data'] ?? [];
        $records = is_array($data) && isset($data['domains']) ? $data['domains'] : $data;
        $domains = [];

        foreach (is_array($records) ? $records : [] as $record) {
            if (! is_array($record)) {
                continue;
            }
            $name = NameserverSet::domain((string) ($record['name'] ?? ''));
            $expiresAt = $this->timestampValue($record['expires_at'] ?? null);
            $options = is_array($record['options'] ?? null) ? $record['options'] : [];
            $domains[] = new RemoteDomain(
                name: $name,
                nameservers: $this->getNameservers($name),
                status: $expiresAt !== null && CarbonImmutable::parse($expiresAt)->isPast() ? 'EXPIRED' : 'ACTIVE',
                tld: is_string($record['tld'] ?? null) ? strtolower($record['tld']) : NameserverSet::tld($name),
                registeredAt: $this->timestampValue($record['created_at'] ?? null),
                expiresAt: $expiresAt,
                renewalPrice: $this->renewalPriceFrom($record),
                isLocked: $this->lockedValue($record),
                privacyEnabled: $this->booleanValue($options['domain_privacy'] ?? null),
                autoRenew: $this->autoRenewValue($record, $options),
            );
        }

        $pagination = is_array($payload['pagination'] ?? null) ? $payload['pagination'] : [];
        $lastPage = $pagination['pages'] ?? $pagination['last_page'] ?? $payload['pages'] ?? null;

        return new DomainPage($domains, is_numeric($lastPage) && $page < (int) $lastPage ? $page + 1 : null);
    }

    public function getNameservers(string $domain): array
    {
        $payload = $this->request('/2/zones/'.rawurlencode(NameserverSet::domain($domain)));
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        return NameserverSet::normalize(is_array($data['nameservers'] ?? null) ? $data['nameservers'] : [], false);
    }

    public function setNameservers(string $domain, array $nameservers): ChangeResult
    {
        throw new ProviderException(
            ErrorCategory::ProviderPermanent,
            'Infomaniak public API does not support changing registrar nameservers.',
        );
    }

    /** @return array<string, mixed> */
    private function request(string $path, array $query = []): array
    {
        try {
            $response = Http::baseUrl('https://api.infomaniak.com')
                ->withToken($this->account->credentials['token'] ?? '')
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout(30)
                ->get($path, $query);
        } catch (ConnectionException) {
            throw new ProviderException(ErrorCategory::Network, 'Unable to connect to Infomaniak.');
        }

        $this->ensureSuccessfulResponse($response);
        $payload = $response->json();
        if (! is_array($payload)) {
            throw new ProviderException(ErrorCategory::ProviderTemporary, 'Infomaniak returned an invalid response.');
        }
        if (isset($payload['result']) && strtolower((string) $payload['result']) !== 'success') {
            $message = $payload['error']['description'] ?? $payload['error']['message'] ?? 'Infomaniak rejected the request.';
            throw new ProviderException(ErrorCategory::ProviderPermanent, mb_substr(strip_tags((string) $message), 0, 500));
        }

        return $payload;
    }

    private function ensureSuccessfulResponse(Response $response): void
    {
        if ($response->successful()) {
            return;
        }
        $category = match ($response->status()) {
            401 => ErrorCategory::Authentication,
            403 => ErrorCategory::Permission,
            404 => ErrorCategory::DomainNotFound,
            429 => ErrorCategory::RateLimit,
            500, 502, 503, 504 => ErrorCategory::ProviderTemporary,
            400, 422 => ErrorCategory::Validation,
            default => ErrorCategory::Unknown,
        };
        $message = $response->json('error.description') ?? $response->json('error.message');
        $retryAfter = is_numeric($response->header('Retry-After')) ? (int) $response->header('Retry-After') : null;

        throw new ProviderException(
            $category,
            is_string($message) ? mb_substr(strip_tags($message), 0, 500) : 'Infomaniak rejected the request.',
            (string) $response->status(),
            $retryAfter,
        );
    }

    private function timestampValue(mixed $value): ?string
    {
        if (is_numeric($value)) {
            return CarbonImmutable::createFromTimestamp((int) $value)->toIso8601String();
        }

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /** @param array<string, mixed> $record */
    private function renewalPriceFrom(array $record): ?float
    {
        foreach (['renewal_price', 'renewalPrice', 'renew_price', 'renewPrice'] as $key) {
            $price = $this->priceValue($record[$key] ?? null);
            if ($price !== null) {
                return $price;
            }
        }

        foreach (['pricing', 'prices'] as $key) {
            $price = $this->priceValue($record[$key] ?? null);
            if ($price !== null) {
                return $price;
            }
        }

        return null;
    }

    private function priceValue(mixed $value): ?float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }
        if (! is_array($value)) {
            return null;
        }

        foreach ($value as $price) {
            if (! is_array($price) || ! in_array(strtolower((string) ($price['operation'] ?? '')), ['renew', 'renewal'], true)) {
                continue;
            }

            return $this->priceValue($price['price'] ?? $price['amount'] ?? $price['value'] ?? null);
        }

        foreach (['renewal_price', 'renewalPrice', 'renew_price', 'renewPrice', 'renewal', 'renew', 'amount', 'price', 'value'] as $key) {
            $price = $this->priceValue($value[$key] ?? null);
            if ($price !== null) {
                return $price;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $record */
    private function lockedValue(array $record): ?bool
    {
        foreach (['is_locked', 'isLocked', 'locked', 'transfer_locked', 'transferLocked', 'transfer_lock', 'transferLock'] as $key) {
            if (array_key_exists($key, $record)) {
                return $this->booleanValue($record[$key]);
            }
        }

        $statuses = $record['epp_statuses'] ?? $record['eppStatuses'] ?? null;
        if (! is_array($statuses)) {
            return null;
        }

        return in_array('clientTransferProhibited', $statuses, true);
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $options
     */
    private function autoRenewValue(array $record, array $options): ?bool
    {
        foreach (['auto_renew', 'autoRenew', 'autorenew'] as $key) {
            if (array_key_exists($key, $record)) {
                return $this->booleanValue($record[$key]);
            }
            if (array_key_exists($key, $options)) {
                return $this->booleanValue($options[$key]);
            }
        }

        return $this->booleanValue($options['renewal_warranty'] ?? null);
    }

    private function booleanValue(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return match (strtolower(trim((string) $value))) {
            '1', 'true', 'yes', 'enabled', 'active', 'on' => true,
            '0', 'false', 'no', 'disabled', 'inactive', 'off', 'none' => false,
            default => null,
        };
    }
}
