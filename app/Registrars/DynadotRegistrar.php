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
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class DynadotRegistrar implements Registrar
{
    private const PAGE_SIZE = 100;

    public function __construct(private readonly RegistrarAccount $account) {}

    public function testConnection(): ConnectionResult
    {
        $this->request('list_domain', ['page_index' => 0, 'count_per_page' => 1]);

        return new ConnectionResult(true, 'Connection successful.');
    }

    public function listDomains(int $page = 1): DomainPage
    {
        $data = $this->request('list_domain', [
            'page_index' => max(0, $page - 1),
            'count_per_page' => self::PAGE_SIZE,
        ]);
        $records = $data['MainDomains'] ?? $data['Domains'] ?? [];
        if ($this->isAssociativeDomain($records)) {
            $records = [$records];
        }

        $domains = [];
        foreach (is_array($records) ? $records : [] as $record) {
            if (! is_array($record)) {
                continue;
            }
            $name = NameserverSet::domain((string) ($record['Name'] ?? $record['DomainName'] ?? ''));
            $expiresAt = $this->dateValue($record['Expiration'] ?? $record['ExpirationDate'] ?? null);
            $status = $this->booleanValue($record['Disabled'] ?? null) === true
                ? 'DISABLED'
                : ($expiresAt !== null && CarbonImmutable::parse($expiresAt)->isPast() ? 'EXPIRED' : 'ACTIVE');

            $domains[] = new RemoteDomain(
                name: $name,
                nameservers: $this->nameserversFrom($record['NameServerSettings'] ?? []),
                status: $status,
                tld: NameserverSet::tld($name),
                registeredAt: $this->dateValue($record['Registration'] ?? $record['RegistrationDate'] ?? null),
                expiresAt: $expiresAt,
                isLocked: $this->booleanValue($record['Locked'] ?? null),
                privacyEnabled: $this->booleanValue($record['Privacy'] ?? $record['WhoisPrivacy'] ?? null),
                autoRenew: $this->booleanValue($record['AutoRenew'] ?? $record['RenewOption'] ?? null),
            );
        }

        $total = $data['TotalCount'] ?? $data['Total'] ?? null;
        $hasNextPage = is_numeric($total)
            ? $page * self::PAGE_SIZE < (int) $total
            : count($domains) === self::PAGE_SIZE;

        return new DomainPage($domains, $hasNextPage ? $page + 1 : null);
    }

    public function getNameservers(string $domain): array
    {
        $data = $this->request('get_ns', ['domain' => NameserverSet::domain($domain)]);

        return $this->nameserversFrom($data['NsContent'] ?? $data['NameServerSettings'] ?? []);
    }

    public function setNameservers(string $domain, array $nameservers): ChangeResult
    {
        $parameters = ['domain' => NameserverSet::domain($domain)];
        foreach (NameserverSet::normalize($nameservers) as $index => $nameserver) {
            $parameters['ns'.$index] = $nameserver;
        }
        $this->request('set_ns', $parameters);

        return new ChangeResult(true);
    }

    /** @return array<string, mixed> */
    private function request(string $command, array $parameters = []): array
    {
        $url = $this->account->environment === RegistrarEnvironment::Sandbox
            ? 'https://api-sandbox.dynadot.com/api3.json'
            : 'https://api.dynadot.com/api3.json';

        try {
            $response = Http::acceptJson()->connectTimeout(10)->timeout(30)->get($url, array_merge([
                'key' => $this->account->credentials['api_key'] ?? '',
                'command' => $command,
            ], $parameters));
        } catch (ConnectionException) {
            throw new ProviderException(ErrorCategory::Network, 'Unable to connect to Dynadot.');
        }

        $this->ensureSuccessfulResponse($response);
        $payload = $response->json();
        if (! is_array($payload)) {
            throw new ProviderException(ErrorCategory::ProviderTemporary, 'Dynadot returned an invalid response.');
        }
        $data = collect($payload)->first(fn (mixed $value, string|int $key): bool => is_array($value) && str_ends_with((string) $key, 'Response'));
        if (! is_array($data)) {
            throw new ProviderException(ErrorCategory::ProviderChanged, 'Dynadot returned an unrecognized response.');
        }

        $code = isset($data['ResponseCode']) ? (string) $data['ResponseCode'] : null;
        $status = strtoupper((string) ($data['Status'] ?? ''));
        if ($code !== '0' && $status !== 'SUCCESS') {
            $message = is_string($data['Error'] ?? null) ? $data['Error'] : (is_string($data['Message'] ?? null) ? $data['Message'] : 'Dynadot rejected the request.');
            $category = match ($code) {
                '1', '2' => ErrorCategory::Authentication,
                '6' => ErrorCategory::DomainNotFound,
                default => ErrorCategory::ProviderPermanent,
            };

            throw new ProviderException($category, mb_substr(strip_tags($message), 0, 500), $code);
        }

        return $data;
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

        throw new ProviderException($category, 'Dynadot rejected the request.', (string) $response->status(), $retryAfter);
    }

    /** @return list<string> */
    private function nameserversFrom(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        uksort($value, 'strnatcasecmp');
        $nameservers = array_filter(
            $value,
            fn (mixed $host, string|int $key): bool => preg_match('/^host\d*$/i', (string) $key) === 1 && is_string($host),
            ARRAY_FILTER_USE_BOTH,
        );

        return NameserverSet::normalize(array_values($nameservers), false);
    }

    private function dateValue(mixed $value): ?string
    {
        try {
            if (is_numeric($value)) {
                $timestamp = (float) $value;
                if ($timestamp > 100000000000) {
                    $timestamp /= 1000;
                }

                return CarbonImmutable::createFromTimestamp($timestamp)->toIso8601String();
            }

            return is_string($value) && trim($value) !== '' ? CarbonImmutable::parse($value)->toIso8601String() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function booleanValue(mixed $value): ?bool
    {
        return match (strtoupper(trim((string) $value))) {
            '1', 'TRUE', 'YES', 'ENABLED', 'ACTIVE', 'AUTO' => true,
            '0', 'FALSE', 'NO', 'DISABLED', 'INACTIVE', 'NONE' => false,
            default => is_bool($value) ? $value : null,
        };
    }

    private function isAssociativeDomain(mixed $records): bool
    {
        return is_array($records) && (isset($records['Name']) || isset($records['DomainName']));
    }
}
