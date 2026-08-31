<?php

namespace App\Registrars;

use App\Enums\ErrorCategory;
use App\Enums\RegistrarEnvironment;
use App\Models\RegistrarAccount;
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

class NameSiloRegistrar implements Registrar
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
        $reply = $this->request('listDomains', ['page' => $page, 'pageSize' => self::PAGE_SIZE]);
        $domainNames = $reply['domains'] ?? [];
        if (is_array($domainNames) && isset($domainNames['domain'])) {
            $domainNames = $domainNames['domain'];
        }
        if (is_string($domainNames)) {
            $domainNames = [$domainNames];
        }

        $domains = [];
        foreach (is_array($domainNames) ? $domainNames : [] as $domainName) {
            $name = NameserverSet::domain(is_array($domainName) ? (string) ($domainName['domain'] ?? $domainName['name'] ?? '') : (string) $domainName);
            $details = $this->request('getDomainInfo', ['domain' => $name]);
            $nameservers = $details['nameservers'] ?? $details['name_servers'] ?? [];
            if (is_array($nameservers) && isset($nameservers['nameserver'])) {
                $nameservers = $nameservers['nameserver'];
            }

            $domains[] = new RemoteDomain(
                name: $name,
                nameservers: NameserverSet::normalize(is_array($nameservers) ? array_values($nameservers) : [], false),
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

    public function getNameservers(string $domain): array
    {
        $reply = $this->request('getDomainInfo', ['domain' => NameserverSet::domain($domain)]);
        $nameservers = $reply['nameservers'] ?? $reply['name_servers'] ?? [];
        if (is_array($nameservers) && isset($nameservers['nameserver'])) {
            $nameservers = $nameservers['nameserver'];
        }

        return NameserverSet::normalize(is_array($nameservers) ? array_values($nameservers) : [], false);
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
    private function request(string $operation, array $parameters = []): array
    {
        $baseUrl = $this->account->environment === RegistrarEnvironment::Sandbox
            ? 'https://sandbox.namesilo.com/api'
            : 'https://www.namesilo.com/api';

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

        $code = isset($reply['code']) ? (string) $reply['code'] : null;
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
        return match (strtoupper(trim((string) $value))) {
            '1', 'TRUE', 'YES', 'ENABLED', 'ACTIVE' => true,
            '0', 'FALSE', 'NO', 'DISABLED', 'INACTIVE' => false,
            default => is_bool($value) ? $value : null,
        };
    }
}
