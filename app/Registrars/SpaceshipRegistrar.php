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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class SpaceshipRegistrar implements Registrar
{
    private const PAGE_SIZE = 100;

    public function __construct(private readonly RegistrarAccount $account) {}

    public function testConnection(): ConnectionResult
    {
        $this->request('get', '/v1/domains', ['take' => 1, 'skip' => 0]);

        return new ConnectionResult(true, 'Connection successful.');
    }

    public function listDomains(int $page = 1): DomainPage
    {
        $skip = max(0, $page - 1) * self::PAGE_SIZE;
        $data = $this->request('get', '/v1/domains', ['take' => self::PAGE_SIZE, 'skip' => $skip]);
        $records = is_array($data['items'] ?? null) ? $data['items'] : [];
        $domains = array_values(array_filter(array_map(fn (mixed $record): ?RemoteDomain => is_array($record) ? $this->remoteDomain($record) : null, $records)));
        $total = $data['total'] ?? null;
        $hasNextPage = is_numeric($total)
            ? $skip + count($domains) < (int) $total
            : count($domains) === self::PAGE_SIZE;

        return new DomainPage($domains, $hasNextPage ? $page + 1 : null);
    }

    public function getNameservers(string $domain): array
    {
        $data = $this->request('get', '/v1/domains/'.rawurlencode(NameserverSet::domain($domain)));

        return $this->nameserversFrom($data);
    }

    public function setNameservers(string $domain, array $nameservers): ChangeResult
    {
        $this->request('put', '/v1/domains/'.rawurlencode(NameserverSet::domain($domain)).'/nameservers', [
            'provider' => 'custom',
            'hosts' => NameserverSet::normalize($nameservers),
        ]);

        return new ChangeResult(true);
    }

    /** @param array<string, mixed> $record */
    private function remoteDomain(array $record): RemoteDomain
    {
        $name = NameserverSet::domain((string) ($record['name'] ?? ''));
        $eppStatuses = is_array($record['eppStatuses'] ?? null) ? $record['eppStatuses'] : [];

        return new RemoteDomain(
            name: $name,
            nameservers: $this->nameserversFrom($record),
            status: is_string($record['lifecycleStatus'] ?? null) ? strtoupper($record['lifecycleStatus']) : null,
            tld: NameserverSet::tld($name),
            registeredAt: is_string($record['registrationDate'] ?? null) ? $record['registrationDate'] : null,
            expiresAt: is_string($record['expirationDate'] ?? null) ? $record['expirationDate'] : null,
            isLocked: in_array('clientTransferProhibited', $eppStatuses, true),
            privacyEnabled: $this->privacyEnabled($record['privacyProtection'] ?? null),
            autoRenew: is_bool($record['autoRenew'] ?? null) ? $record['autoRenew'] : null,
        );
    }

    /** @return list<string> */
    private function nameserversFrom(array $data): array
    {
        $nameservers = $data['nameservers']['hosts'] ?? $data['nameservers'] ?? [];

        return NameserverSet::normalize(is_array($nameservers) ? array_values($nameservers) : [], false);
    }

    private function privacyEnabled(mixed $privacy): ?bool
    {
        if (is_bool($privacy)) {
            return $privacy;
        }
        if (! is_array($privacy)) {
            return null;
        }

        $value = $privacy['enabled'] ?? $privacy['level'] ?? null;
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return ! in_array(strtolower($value), ['none', 'disabled', 'off'], true);
        }

        return $privacy !== [];
    }

    /** @return array<string, mixed> */
    private function request(string $method, string $path, array $data = []): array
    {
        try {
            $pending = Http::baseUrl('https://spaceship.dev/api')
                ->withHeaders([
                    'X-API-Key' => $this->account->credentials['api_key'] ?? '',
                    'X-API-Secret' => $this->account->credentials['api_secret'] ?? '',
                ])
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout(30);
            $response = match ($method) {
                'get' => $pending->get($path, $data),
                'put' => $pending->put($path, $data),
            };
        } catch (ConnectionException) {
            throw new ProviderException(ErrorCategory::Network, 'Unable to connect to Spaceship.');
        }

        $this->ensureSuccessfulResponse($response);
        $payload = $response->json();
        if ($response->noContent()) {
            return [];
        }
        if (! is_array($payload)) {
            throw new ProviderException(ErrorCategory::ProviderTemporary, 'Spaceship returned an invalid response.');
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
            409 => ErrorCategory::Conflict,
            429 => ErrorCategory::RateLimit,
            500, 502, 503, 504 => ErrorCategory::ProviderTemporary,
            400, 422 => ErrorCategory::Validation,
            default => ErrorCategory::Unknown,
        };
        $message = $response->json('message') ?? $response->json('detail');
        $retryAfter = is_numeric($response->header('Retry-After')) ? (int) $response->header('Retry-After') : null;

        throw new ProviderException(
            $category,
            is_string($message) ? mb_substr(strip_tags($message), 0, 500) : 'Spaceship rejected the request.',
            (string) $response->status(),
            $retryAfter,
        );
    }
}
