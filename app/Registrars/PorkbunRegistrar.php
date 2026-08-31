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
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class PorkbunRegistrar implements Registrar
{
    private const PAGE_SIZE = 1000;

    private const NAMESERVER_CONCURRENCY = 5;

    private const NAMESERVER_BATCH_SIZE = 100;

    public function __construct(private readonly RegistrarAccount $account) {}

    public function testConnection(): ConnectionResult
    {
        $this->request('get', '/domain/listAll', ['start' => 0]);

        return new ConnectionResult(true, 'Connection successful.');
    }

    public function listDomains(int $page = 1): DomainPage
    {
        $start = max(0, $page - 1) * self::PAGE_SIZE;
        $data = $this->request('get', '/domain/listAll', ['start' => $start]);
        $records = is_array($data['domains'] ?? null) ? $data['domains'] : [];
        $domainNames = [];

        foreach ($records as $record) {
            if (is_array($record)) {
                $domainNames[] = NameserverSet::domain((string) ($record['domain'] ?? ''));
            }
        }

        $nameserversByDomain = $this->nameserversFor($domainNames);
        $domains = [];

        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }
            $name = NameserverSet::domain((string) ($record['domain'] ?? ''));
            $domains[] = new RemoteDomain(
                name: $name,
                nameservers: $nameserversByDomain[$name] ?? [],
                status: is_string($record['status'] ?? null) ? strtoupper($record['status']) : 'ACTIVE',
                tld: is_string($record['tld'] ?? null) ? strtolower($record['tld']) : NameserverSet::tld($name),
                registeredAt: $this->dateValue($record['createDate'] ?? null),
                expiresAt: $this->dateValue($record['expireDate'] ?? null),
                isLocked: $this->booleanValue($record['securityLock'] ?? null),
                privacyEnabled: $this->booleanValue($record['whoisPrivacy'] ?? null),
                autoRenew: $this->booleanValue($record['autoRenew'] ?? null),
            );
        }

        $total = $data['total'] ?? $data['totalDomains'] ?? null;
        $hasNextPage = is_numeric($total)
            ? $start + count($domains) < (int) $total
            : count($domains) === self::PAGE_SIZE;

        return new DomainPage($domains, $hasNextPage ? $page + 1 : null);
    }

    public function getNameservers(string $domain): array
    {
        $data = $this->request('get', '/domain/getNs/'.rawurlencode(NameserverSet::domain($domain)));

        return NameserverSet::normalize(is_array($data['ns'] ?? null) ? $data['ns'] : [], false);
    }

    public function setNameservers(string $domain, array $nameservers): ChangeResult
    {
        $this->request('post', '/domain/updateNs/'.rawurlencode(NameserverSet::domain($domain)), [
            'ns' => NameserverSet::normalize($nameservers),
        ]);

        return new ChangeResult(true);
    }

    /** @return array<string, mixed> */
    private function request(string $method, string $path, array $data = []): array
    {
        try {
            $pending = Http::baseUrl('https://api.porkbun.com/api/json/v3')
                ->withHeaders($this->authenticationHeaders())
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout(30);
            $response = $method === 'get' ? $pending->get($path, $data) : $pending->post($path, $data);
        } catch (ConnectionException) {
            throw new ProviderException(ErrorCategory::Network, 'Unable to connect to Porkbun.');
        }

        return $this->payload($response);
    }

    /**
     * @param  list<string>  $domains
     * @return array<string, list<string>>
     */
    private function nameserversFor(array $domains): array
    {
        $nameservers = [];

        foreach (array_chunk($domains, self::NAMESERVER_BATCH_SIZE) as $domainBatch) {
            $responses = Http::pool(fn (Pool $pool): array => array_map(
                fn (string $domain) => $pool
                    ->as($domain)
                    ->withHeaders($this->authenticationHeaders())
                    ->acceptJson()
                    ->connectTimeout(10)
                    ->timeout(30)
                    ->get('https://api.porkbun.com/api/json/v3/domain/getNs/'.rawurlencode($domain)),
                $domainBatch,
            ), self::NAMESERVER_CONCURRENCY);

            foreach ($domainBatch as $domain) {
                $response = $responses[$domain] ?? null;
                if ($response instanceof Throwable) {
                    throw new ProviderException(ErrorCategory::Network, "Unable to retrieve nameservers for {$domain} from Porkbun.");
                }
                if (! $response instanceof Response) {
                    throw new ProviderException(ErrorCategory::ProviderTemporary, "Porkbun returned no nameserver response for {$domain}.");
                }

                try {
                    $payload = $this->payload($response);
                } catch (ProviderException $exception) {
                    throw new ProviderException(
                        $exception->category,
                        "Porkbun nameserver lookup failed for {$domain}: {$exception->getMessage()}",
                        $exception->providerCode,
                        $exception->retryAfter,
                    );
                }

                $nameservers[$domain] = NameserverSet::normalize(is_array($payload['ns'] ?? null) ? $payload['ns'] : [], false);
            }
        }

        return $nameservers;
    }

    /** @return array<string, mixed> */
    private function payload(Response $response): array
    {
        $this->ensureSuccessfulResponse($response);
        $payload = $response->json();
        if (! is_array($payload)) {
            throw new ProviderException(ErrorCategory::ProviderTemporary, 'Porkbun returned an invalid response.');
        }
        if (strtoupper((string) ($payload['status'] ?? '')) !== 'SUCCESS') {
            $message = is_string($payload['message'] ?? null) ? $payload['message'] : 'Porkbun rejected the request.';
            throw new ProviderException(ErrorCategory::ProviderPermanent, mb_substr(strip_tags($message), 0, 500));
        }

        return $payload;
    }

    /** @return array<string, string> */
    private function authenticationHeaders(): array
    {
        return [
            'X-API-Key' => $this->account->credentials['api_key'] ?? '',
            'X-Secret-API-Key' => $this->account->credentials['secret_api_key'] ?? '',
        ];
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
        $message = $response->json('message');
        $retryAfter = is_numeric($response->header('Retry-After')) ? (int) $response->header('Retry-After') : null;

        throw new ProviderException(
            $category,
            is_string($message) ? mb_substr(strip_tags($message), 0, 500) : 'Porkbun rejected the request.',
            (string) $response->status(),
            $retryAfter,
        );
    }

    private function dateValue(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toIso8601String();
        } catch (Throwable) {
            return $value;
        }
    }

    private function booleanValue(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        return match (strtoupper(trim((string) $value))) {
            'TRUE', 'YES', 'ENABLED', 'ACTIVE' => true,
            'FALSE', 'NO', 'DISABLED', 'INACTIVE' => false,
            default => null,
        };
    }
}
