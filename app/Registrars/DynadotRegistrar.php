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
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Throwable;

class DynadotRegistrar implements Registrar
{
    private const PAGE_SIZE = 100;

    /** @var array<string, float>|null */
    private ?array $renewalPrices = null;

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
            $tld = NameserverSet::tld($name);
            $expiresAt = $this->dateValue($record['Expiration'] ?? $record['ExpirationDate'] ?? null);
            $status = $this->booleanValue($record['Disabled'] ?? null) === true
                ? 'DISABLED'
                : ($expiresAt !== null && CarbonImmutable::parse($expiresAt)->isPast() ? 'EXPIRED' : 'ACTIVE');
            $nameservers = $this->nameserversFrom($record['NameServerSettings'] ?? []);
            if ($nameservers === [] && $name !== '') {
                Sleep::for(1)->second();
                $nameservers = $this->getNameservers($name);
            }

            $domains[] = new RemoteDomain(
                name: $name,
                nameservers: $nameservers,
                status: $status,
                tld: $tld,
                renewalPrice: $this->renewalPrice($tld),
                registeredAt: $this->dateValue($record['Registration'] ?? $record['RegistrationDate'] ?? null),
                expiresAt: $expiresAt,
                isLocked: $this->booleanValue($record['Locked'] ?? null),
                privacyEnabled: $this->privacyEnabled($record['Privacy'] ?? $record['WhoisPrivacy'] ?? null),
                autoRenew: $this->autoRenewEnabled($record['AutoRenew'] ?? $record['RenewOption'] ?? null),
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

        $serverRecords = $value['NameServers'] ?? [];
        if (is_array($serverRecords) && isset($serverRecords['ServerName'])) {
            $serverRecords = [$serverRecords];
        }
        $nestedNameservers = [];
        foreach (is_array($serverRecords) ? $serverRecords : [] as $serverRecord) {
            if (is_string($serverRecord)) {
                $nestedNameservers[] = $serverRecord;

                continue;
            }
            if (! is_array($serverRecord)) {
                continue;
            }
            $serverName = $serverRecord['ServerName'] ?? $serverRecord['Host'] ?? null;
            if (is_string($serverName)) {
                $nestedNameservers[] = $serverName;
            }
        }
        if ($nestedNameservers !== []) {
            return NameserverSet::normalize($nestedNameservers, false);
        }

        uksort($value, 'strnatcasecmp');
        $nameservers = array_filter(
            $value,
            fn (mixed $host, string|int $key): bool => preg_match('/^host\d*$/i', (string) $key) === 1 && is_string($host),
            ARRAY_FILTER_USE_BOTH,
        );

        return NameserverSet::normalize(array_values($nameservers), false);
    }

    private function renewalPrice(string $tld): ?float
    {
        if ($this->renewalPrices === null) {
            $data = $this->request('tld_price', [
                'currency' => 'USD',
                'count_per_page' => 1000,
                'page_index' => 0,
            ]);
            $this->renewalPrices = [];
            $prices = $data['TldPrice'] ?? [];
            if (is_array($prices) && isset($prices['Tld'])) {
                $prices = [$prices];
            }
            foreach (is_array($prices) ? $prices : [] as $price) {
                if (! is_array($price)) {
                    continue;
                }
                $name = $price['Tld'] ?? null;
                $renewalPrice = $price['Price']['Renew'] ?? null;
                if (is_string($name) && is_numeric($renewalPrice)) {
                    $this->renewalPrices[ltrim(strtolower(trim($name)), '.')] = (float) $renewalPrice;
                }
            }
        }

        return $this->renewalPrices[strtolower($tld)] ?? null;
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

    private function privacyEnabled(mixed $value): ?bool
    {
        return match ($this->normalizedOption($value)) {
            'full', 'partial' => true,
            'off' => false,
            default => $this->booleanValue($value),
        };
    }

    private function autoRenewEnabled(mixed $value): ?bool
    {
        return match ($this->normalizedOption($value)) {
            'auto', 'auto renew', 'auto renewal', 'autorenew', 'autorenewal' => true,
            'donot', 'donot renew', 'do not renew', 'no renew option', 'manual', 'manual renew', 'manual renewal' => false,
            default => $this->booleanValue($value),
        };
    }

    private function normalizedOption(mixed $value): string
    {
        return Str::of((string) $value)
            ->trim()
            ->replace(['_', '-'], ' ')
            ->squish()
            ->lower()
            ->toString();
    }

    private function isAssociativeDomain(mixed $records): bool
    {
        return is_array($records) && (isset($records['Name']) || isset($records['DomainName']));
    }
}
