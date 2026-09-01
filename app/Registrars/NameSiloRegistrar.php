<?php

namespace App\Registrars;

use App\Enums\ErrorCategory;
use App\Enums\RegistrarEnvironment;
use App\Models\RegistrarAccount;
use App\Registrars\Contracts\ProvidesRenewalPrices;
use App\Registrars\Contracts\Registrar;
use App\Registrars\DTO\ChangeResult;
use App\Registrars\DTO\ConnectionResult;
use App\Registrars\DTO\DomainPage;
use App\Registrars\DTO\RemoteDomain;
use App\Registrars\Exceptions\ProviderException;
use App\Services\NameserverSet;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NameSiloRegistrar implements ProvidesRenewalPrices, Registrar
{
    private const PAGE_SIZE = 1000;

    public function __construct(private readonly RegistrarAccount $account) {}

    public function testConnection(): ConnectionResult
    {
        $this->request('listDomains', ['page' => 1, 'pageSize' => 1]);

        return new ConnectionResult(true, 'Connection successful.');
    }

    public function listDomains(int $page = 1): DomainPage
    {
        $reply = $this->request('listDomains', ['page' => $page, 'pageSize' => self::PAGE_SIZE], batch: true);
        $domainNames = $this->domainNamesFrom($reply['domains'] ?? []);

        $domains = [];
        foreach ($domainNames as $name) {
            $details = $this->request('getDomainInfo', ['domain' => $name], batch: true);

            $domains[] = new RemoteDomain(
                name: $name,
                nameservers: $this->nameserversFrom($details['nameservers'] ?? $details['name_servers'] ?? []),
                status: $this->stringValue($details, ['status']),
                tld: NameserverSet::tld($name),
                registeredAt: $this->stringValue($details, ['created', 'created_at']),
                expiresAt: $this->stringValue($details, ['expires', 'expires_at']),
                isLocked: $this->booleanValue($details['locked'] ?? null),
                privacyEnabled: $this->booleanValue($details['private'] ?? $details['privacy'] ?? null),
                autoRenew: $this->booleanValue($details['auto_renew'] ?? $details['autoRenew'] ?? null),
            );
        }

        $total = $reply['totalDomains'] ?? $reply['total'] ?? null;
        $hasNextPage = is_numeric($total)
            ? $page * self::PAGE_SIZE < (int) $total
            : count($domains) === self::PAGE_SIZE;

        return new DomainPage($domains, $hasNextPage ? $page + 1 : null);
    }

    public function renewalPrices(array $tlds): array
    {
        $reply = $this->request('getPrices', batch: true);
        $requestedTlds = array_fill_keys(array_map(
            fn (string $tld): string => ltrim(strtolower(trim($tld)), '.'),
            $tlds,
        ), true);
        $renewalPrices = [];

        foreach ($reply as $tld => $prices) {
            $normalizedTld = ltrim(strtolower((string) $tld), '.');
            $renewalPrice = is_array($prices) ? $this->scalarString($prices['renew'] ?? null) : null;
            if (isset($requestedTlds[$normalizedTld]) && is_numeric($renewalPrice)) {
                $renewalPrices[$normalizedTld] = (float) $renewalPrice;
            }
        }

        $missingTlds = array_values(array_diff(array_keys($requestedTlds), array_keys($renewalPrices)));
        if ($missingTlds !== []) {
            Log::warning('NameSilo renewal prices were missing from the pricing response.', [
                'registrar_account_id' => $this->account->id,
                'tlds' => $missingTlds,
            ]);
        }

        return $renewalPrices;
    }

    public function getNameservers(string $domain): array
    {
        $reply = $this->request('getDomainInfo', ['domain' => NameserverSet::domain($domain)]);

        return $this->nameserversFrom($reply['nameservers'] ?? $reply['name_servers'] ?? []);
    }

    public function setNameservers(string $domain, array $nameservers): ChangeResult
    {
        $parameters = ['domain' => NameserverSet::domain($domain)];
        foreach (NameserverSet::normalize($nameservers) as $index => $nameserver) {
            $parameters['ns'.($index + 1)] = $nameserver;
        }
        $this->request('changeNameServers', $parameters);

        return new ChangeResult(true);
    }

    /** @return array<string, mixed> */
    private function request(string $operation, array $parameters = [], bool $batch = false): array
    {
        $host = $this->account->environment === RegistrarEnvironment::Sandbox
            ? 'https://sandbox.namesilo.com'
            : 'https://www.namesilo.com';
        $baseUrl = $host.($batch ? '/apibatch' : '/api');

        try {
            $response = Http::acceptJson()->connectTimeout(10)->timeout(30)->get("{$baseUrl}/{$operation}", array_merge([
                'version' => 1,
                'type' => 'json',
                'key' => $this->account->credentials['api_key'] ?? '',
            ], $parameters));
        } catch (ConnectionException) {
            throw new ProviderException(ErrorCategory::Network, 'Unable to connect to NameSilo.');
        }

        $this->ensureSuccessfulResponse($response);
        $reply = $response->json('reply');
        if (! is_array($reply)) {
            throw new ProviderException(ErrorCategory::ProviderTemporary, 'NameSilo returned an invalid response.');
        }

        $code = $this->scalarString($reply['code'] ?? null);
        if ($code !== '300') {
            $message = is_string($reply['detail'] ?? null) ? $reply['detail'] : 'NameSilo rejected the request.';
            $category = match ($code) {
                '110' => ErrorCategory::Authentication,
                '280' => ErrorCategory::DomainNotFound,
                default => ErrorCategory::ProviderPermanent,
            };

            throw new ProviderException($category, mb_substr(strip_tags($message), 0, 500), $code);
        }

        return $reply;
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
        $retryAfter = is_numeric($response->header('Retry-After')) ? (int) $response->header('Retry-After') : null;

        throw new ProviderException($category, 'NameSilo rejected the request.', (string) $response->status(), $retryAfter);
    }

    /** @param list<string> $keys */
    private function stringValue(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (is_string($data[$key] ?? null) && trim($data[$key]) !== '') {
                return $data[$key];
            }
        }

        return null;
    }

    private function booleanValue(mixed $value): ?bool
    {
        $value = $this->scalarString($value);
        if ($value === null) {
            return null;
        }

        return match (strtoupper(trim($value))) {
            '1', 'TRUE', 'YES', 'ENABLED', 'ACTIVE' => true,
            '0', 'FALSE', 'NO', 'DISABLED', 'INACTIVE' => false,
            default => null,
        };
    }

    /** @return list<string> */
    private function domainNamesFrom(mixed $value): array
    {
        $domainName = $this->scalarString($value);
        if ($domainName !== null) {
            return [NameserverSet::domain($domainName)];
        }
        if (! is_array($value)) {
            return [];
        }

        foreach (['domains', 'domain'] as $key) {
            if (array_key_exists($key, $value)) {
                return $this->domainNamesFrom($value[$key]);
            }
        }

        $domainNames = [];
        foreach ($value as $domain) {
            $domainNames = [...$domainNames, ...$this->domainNamesFrom($domain)];
        }

        return array_values(array_unique(array_filter($domainNames)));
    }

    /** @return list<string> */
    private function nameserversFrom(mixed $value): array
    {
        $nameserver = $this->scalarString($value);
        if ($nameserver !== null) {
            return NameserverSet::normalize([$nameserver], false);
        }
        if (! is_array($value)) {
            return [];
        }

        foreach (['nameservers', 'name_servers', 'nameserver', 'name_server', 'host', 'hostname'] as $key) {
            if (array_key_exists($key, $value)) {
                return $this->nameserversFrom($value[$key]);
            }
        }

        $nameservers = [];
        foreach ($value as $key => $item) {
            if (is_int($key) || preg_match('/^ns\d+$/i', (string) $key) === 1) {
                $nameservers = [...$nameservers, ...$this->nameserversFrom($item)];
            }
        }

        return NameserverSet::normalize($nameservers, false);
    }

    private function scalarString(mixed $value): ?string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_string($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (! is_array($value)) {
            return null;
        }

        foreach (['value', 'content', '#text', '_'] as $key) {
            if (array_key_exists($key, $value)) {
                return $this->scalarString($value[$key]);
            }
        }

        return count($value) === 1 ? $this->scalarString(reset($value)) : null;
    }
}
