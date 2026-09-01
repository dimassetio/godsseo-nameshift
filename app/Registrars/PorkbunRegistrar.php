<?php

namespace App\Registrars;

use App\Enums\ErrorCategory;
use App\Models\RegistrarAccount;
use App\Registrars\Contracts\ProvidesRenewalPrices;
use App\Registrars\Contracts\Registrar;
use App\Registrars\Contracts\RequiresNameserverEnrichment;
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
use Illuminate\Support\Str;
use Throwable;

class PorkbunRegistrar implements ProvidesRenewalPrices, Registrar, RequiresNameserverEnrichment
{
    private const PAGE_SIZE = 1000;

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
        $domains = [];

        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }
            $name = NameserverSet::domain((string) ($record['domain'] ?? ''));
            $domains[] = new RemoteDomain(
                name: $name,
                nameservers: [],
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

    public function renewalPrices(array $tlds): array
    {
        $payload = $this->request('get', '/pricing/get');
        $pricing = is_array($payload['pricing'] ?? null) ? $payload['pricing'] : [];
        $requestedTlds = array_fill_keys(array_map(
            fn (string $tld): string => ltrim(strtolower(trim($tld)), '.'),
            $tlds,
        ), true);
        $renewalPrices = [];

        foreach ($pricing as $tld => $prices) {
            $normalizedTld = ltrim(strtolower((string) $tld), '.');
            $renewalPrice = is_array($prices) ? ($prices['renewal'] ?? null) : null;
            if (isset($requestedTlds[$normalizedTld]) && is_numeric($renewalPrice)) {
                $renewalPrices[$normalizedTld] = (float) $renewalPrice;
            }
        }

        return $renewalPrices;
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

        return $this->payload($response, $path);
    }

    /** @return array<string, mixed> */
    private function payload(Response $response, string $endpoint): array
    {
        $payload = $response->json();

        if (! $response->successful()) {
            throw $this->failureException($response, is_array($payload) ? $payload : null, $endpoint);
        }

        if (! is_array($payload)) {
            throw $this->invalidResponseException($response, $endpoint);
        }

        if (strtoupper((string) ($payload['status'] ?? '')) !== 'SUCCESS') {
            throw $this->failureException($response, $payload, $endpoint);
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

    /** @param array<string, mixed>|null $payload */
    private function failureException(Response $response, ?array $payload, string $endpoint): ProviderException
    {
        $providerCode = $this->stringValue($payload['code'] ?? null);
        $providerMessage = $this->stringValue($payload['message'] ?? null);
        $requestId = $this->stringValue($payload['requestId'] ?? null)
            ?? $this->stringValue($response->header('X-Request-Id'));
        $nextAction = is_array($payload['next_action'] ?? null) ? $payload['next_action'] : [];
        $nextActionHint = $this->stringValue($nextAction['hint'] ?? null);
        $retryAfterValue = $response->header('Retry-After') ?: ($payload['ttlRemaining'] ?? null);
        $retryAfter = is_numeric($retryAfterValue) ? (int) $retryAfterValue : null;
        $category = $this->errorCategory($response->status(), $providerCode);
        $message = $this->failureMessage(
            $response,
            $providerCode,
            $providerMessage,
            $requestId,
            $nextActionHint,
            $payload !== null,
        );

        Log::error('Porkbun API request failed.', [
            'registrar_account_id' => $this->account->getKey(),
            'endpoint' => $endpoint,
            'http_status' => $response->status(),
            'provider_code' => $providerCode,
            'provider_message' => $providerMessage,
            'request_id' => $requestId,
            'next_action_type' => $this->stringValue($nextAction['type'] ?? null),
            'next_action_hint' => $nextActionHint,
            'next_action_retryable' => is_bool($nextAction['retryable'] ?? null) ? $nextAction['retryable'] : null,
            'next_action_url' => $this->stringValue($nextAction['url'] ?? null),
            'retry_after' => $retryAfter,
            'rate_limit' => $this->stringValue($response->header('X-RateLimit-Limit')),
            'rate_limit_remaining' => $this->stringValue($response->header('X-RateLimit-Remaining')),
            'rate_limit_reset' => $this->stringValue($response->header('X-RateLimit-Reset')),
            'api_version' => $this->stringValue($response->header('X-API-Version')),
            'content_type' => $this->stringValue($response->header('Content-Type')),
            'response_format' => $payload === null ? 'non_json' : 'json',
            'response_body_excerpt' => $this->responseExcerpt($response),
        ]);

        return new ProviderException(
            $category,
            $message,
            $providerCode ?? 'HTTP_'.$response->status(),
            $retryAfter,
        );
    }

    private function invalidResponseException(Response $response, string $endpoint): ProviderException
    {
        $requestId = $this->stringValue($response->header('X-Request-Id'));
        $contentType = $this->stringValue($response->header('Content-Type'));
        $message = 'Porkbun returned a non-JSON response with HTTP '.$response->status();

        if ($contentType !== null) {
            $message .= " ({$contentType})";
        }

        if ($requestId !== null) {
            $message .= ". Request ID: {$requestId}";
        }

        Log::error('Porkbun API returned an invalid response.', [
            'registrar_account_id' => $this->account->getKey(),
            'endpoint' => $endpoint,
            'http_status' => $response->status(),
            'request_id' => $requestId,
            'api_version' => $this->stringValue($response->header('X-API-Version')),
            'content_type' => $contentType,
            'response_format' => 'non_json',
            'response_body_excerpt' => $this->responseExcerpt($response),
        ]);

        return new ProviderException(
            ErrorCategory::ProviderTemporary,
            Str::limit($message, 500, ''),
            'INVALID_RESPONSE',
        );
    }

    private function errorCategory(int $httpStatus, ?string $providerCode): ErrorCategory
    {
        $codeCategory = match ($providerCode) {
            'API_KEY_REQUIRED', 'INVALID_API_KEYS_001', 'INVALID_API_KEYS_002', 'INVALID_TOKEN', 'INVALID_USER', 'MISSING_SECRETAPIKEY' => ErrorCategory::Authentication,
            'IP_NOT_ALLOWED', 'DOMAIN_NOT_ALLOWED' => ErrorCategory::Permission,
            'RATE_LIMIT_EXCEEDED' => ErrorCategory::RateLimit,
            'INVALID_DOMAIN' => ErrorCategory::DomainNotFound,
            default => null,
        };

        if ($codeCategory !== null) {
            return $codeCategory;
        }

        return match ($httpStatus) {
            401 => ErrorCategory::Authentication,
            403 => ErrorCategory::Permission,
            404 => ErrorCategory::DomainNotFound,
            429 => ErrorCategory::RateLimit,
            500, 502, 503, 504 => ErrorCategory::ProviderTemporary,
            400, 422 => ErrorCategory::Validation,
            200, 201, 202, 204 => ErrorCategory::ProviderPermanent,
            default => ErrorCategory::Unknown,
        };
    }

    private function failureMessage(
        Response $response,
        ?string $providerCode,
        ?string $providerMessage,
        ?string $requestId,
        ?string $nextActionHint,
        bool $isJson,
    ): string {
        $message = 'Porkbun request failed';

        if ($providerCode !== null) {
            $message .= " [{$providerCode}]";
        }

        $message .= ' (HTTP '.$response->status().'): ';
        $message .= $providerMessage
            ?? ($isJson ? 'Porkbun did not provide an error message.' : 'Porkbun returned a non-JSON response.');

        if ($nextActionHint !== null) {
            $message .= " Next action: {$nextActionHint}";
        }

        if ($requestId !== null) {
            $message .= " Request ID: {$requestId}";
        }

        return Str::limit(strip_tags($message), 500, '');
    }

    private function responseExcerpt(Response $response): ?string
    {
        $body = str_replace(
            array_filter([
                $this->account->credentials['api_key'] ?? null,
                $this->account->credentials['secret_api_key'] ?? null,
            ], fn (mixed $credential): bool => is_string($credential) && $credential !== ''),
            '[REDACTED]',
            $response->body(),
        );
        $excerpt = Str::of(strip_tags($body))->squish()->limit(500, '')->toString();

        return $excerpt !== '' ? $excerpt : null;
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
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
