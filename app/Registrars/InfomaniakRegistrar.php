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
use Illuminate\Support\Facades\Log;

class InfomaniakRegistrar implements Registrar
{
    /** @var array<string, float|null> */
    private array $renewalPrices = [];

    public function __construct(private readonly RegistrarAccount $account) {}

    public function testConnection(): ConnectionResult
    {
        $this->request('get', '/2/domains/domains', ['page' => 1]);

        return new ConnectionResult(true, 'Connection successful.');
    }

    public function listDomains(int $page = 1): DomainPage
    {
        $payload = $this->request('get', '/2/domains/domains', ['page' => $page]);
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
            $tld = is_string($record['tld'] ?? null) ? strtolower($record['tld']) : NameserverSet::tld($name);
            $domains[] = new RemoteDomain(
                name: $name,
                nameservers: $this->getNameservers($name),
                status: $expiresAt !== null && CarbonImmutable::parse($expiresAt)->isPast() ? 'EXPIRED' : 'ACTIVE',
                tld: $tld,
                registeredAt: $this->timestampValue($record['created_at'] ?? null),
                expiresAt: $expiresAt,
                renewalPrice: $this->renewalPrice($tld),
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
        $payload = $this->request('get', '/2/zones/'.rawurlencode(NameserverSet::domain($domain)));
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        return NameserverSet::normalize(is_array($data['nameservers'] ?? null) ? $data['nameservers'] : [], false);
    }

    public function setNameservers(string $domain, array $nameservers): ChangeResult
    {
        $payload = $this->request(
            'put',
            '/2/domains/domains/'.rawurlencode(NameserverSet::domain($domain)).'/nameservers',
            ['nameservers' => NameserverSet::normalize($nameservers)],
        );

        return new ChangeResult(($payload['data'] ?? null) === true);
    }

    /** @return array<string, mixed> */
    private function request(string $method, string $path, array $data = []): array
    {
        try {
            $request = Http::baseUrl('https://api.infomaniak.com')
                ->withToken($this->account->credentials['token'] ?? '')
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout(30);
            $response = match (strtolower($method)) {
                'get' => $request->get($path, $data),
                'put' => $request->put($path, $data),
                default => throw new ProviderException(ErrorCategory::Validation, 'Unsupported Infomaniak request method.'),
            };
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

    private function renewalPrice(string $tld): ?float
    {
        $normalizedTld = ltrim(strtolower(trim($tld)), '.');
        if (array_key_exists($normalizedTld, $this->renewalPrices)) {
            return $this->renewalPrices[$normalizedTld];
        }

        try {
            $response = Http::baseUrl('https://www.infomaniak.com')
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout(30)
                ->get('/api-g/tldprice', ['country' => 'CH', 'ext' => $normalizedTld]);
        } catch (ConnectionException) {
            throw new ProviderException(ErrorCategory::Network, "Unable to fetch Infomaniak renewal price for .{$normalizedTld}.");
        }

        if (! $response->successful()) {
            throw new ProviderException(
                $response->serverError() ? ErrorCategory::ProviderTemporary : ErrorCategory::ProviderPermanent,
                "Infomaniak renewal price lookup failed for .{$normalizedTld} (HTTP {$response->status()}).",
                (string) $response->status(),
            );
        }

        $prices = $response->json('data.aSellPricesDiscounted') ?? $response->json('data.aSellPrices');
        if (! is_array($prices)) {
            Log::warning('Infomaniak renewal price was not present in the pricing response.', [
                'registrar_account_id' => $this->account->id,
                'tld' => $normalizedTld,
            ]);

            return $this->renewalPrices[$normalizedTld] = null;
        }

        foreach ($prices as $price) {
            if (! is_array($price)) {
                continue;
            }
            $renewalPrice = $price['fRenewExclTax'] ?? $price['fRenew'] ?? null;
            if (is_numeric($renewalPrice)) {
                return $this->renewalPrices[$normalizedTld] = (float) $renewalPrice;
            }
        }

        Log::warning('Infomaniak renewal price contained no numeric renewal value.', [
            'registrar_account_id' => $this->account->id,
            'tld' => $normalizedTld,
        ]);

        return $this->renewalPrices[$normalizedTld] = null;
    }

    /** @param array<string, mixed> $record */
    private function lockedValue(array $record): ?bool
    {
        foreach (['is_locked', 'isLocked', 'locked', 'transfer_locked', 'transferLocked', 'transfer_lock', 'transferLock'] as $key) {
            if (array_key_exists($key, $record)) {
                return $this->booleanValue($record[$key]);
            }
        }

        $statuses = $record['status'] ?? $record['epp_statuses'] ?? $record['eppStatuses'] ?? null;
        if (! is_array($statuses)) {
            return null;
        }

        foreach ($statuses as $status) {
            if (is_array($status)) {
                $status = $status['status'] ?? $status['code'] ?? $status['name'] ?? $status['value'] ?? null;
            }
            if (! is_string($status)) {
                continue;
            }
            if (in_array(strtolower($status), ['clienttransferprohibited', 'servertransferprohibited'], true)) {
                return true;
            }
        }

        return false;
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
