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

class NameComRegistrar implements Registrar
{
    public function __construct(private readonly RegistrarAccount $account) {}

    public function testConnection(): ConnectionResult
    {
        $this->request('get', '/domains', ['perPage' => 1]);

        return new ConnectionResult(true, 'Connection successful.');
    }

    public function listDomains(int $page = 1): DomainPage
    {
        $data = $this->request('get', '/domains', ['page' => $page, 'perPage' => 250])->json();
        $domains = array_map(function (array $domain): RemoteDomain {
            $name = NameserverSet::domain($domain['domainName']);
            $expiresAt = is_string($domain['expireDate'] ?? null) ? $domain['expireDate'] : null;

            return new RemoteDomain(
                name: $name,
                nameservers: NameserverSet::normalize($domain['nameservers'] ?? [], false),
                status: is_string($domain['status'] ?? null) ? $domain['status'] : $this->statusFromExpiration($expiresAt),
                tld: NameserverSet::tld($name),
                renewalPrice: is_numeric($domain['renewalPrice'] ?? null) ? (float) $domain['renewalPrice'] : null,
                registeredAt: is_string($domain['createDate'] ?? null) ? $domain['createDate'] : null,
                expiresAt: $expiresAt,
                isLocked: is_bool($domain['locked'] ?? null) ? $domain['locked'] : null,
                privacyEnabled: is_bool($domain['privacyEnabled'] ?? null) ? $domain['privacyEnabled'] : null,
                autoRenew: is_bool($domain['autorenewEnabled'] ?? null) ? $domain['autorenewEnabled'] : null,
            );
        }, $data['domains'] ?? []);

        return new DomainPage($domains, isset($data['nextPage']) && $data['nextPage'] ? (int) $data['nextPage'] : null);
    }

    private function statusFromExpiration(?string $expiresAt): string
    {
        if ($expiresAt === null) {
            return 'ACTIVE';
        }

        try {
            return CarbonImmutable::parse($expiresAt)->isPast() ? 'EXPIRED' : 'ACTIVE';
        } catch (\Throwable) {
            return 'ACTIVE';
        }
    }

    public function getNameservers(string $domain): array
    {
        $data = $this->request('get', '/domains/'.rawurlencode($domain))->json();

        return NameserverSet::normalize($data['nameservers'] ?? [], false);
    }

    public function setNameservers(string $domain, array $nameservers): ChangeResult
    {
        $this->request('post', '/domains/'.rawurlencode($domain).':setNameservers', ['nameservers' => $nameservers]);

        return new ChangeResult(true);
    }

    private function request(string $method, string $path, array $data = []): Response
    {
        $base = $this->account->environment === RegistrarEnvironment::Sandbox
            ? 'https://api.dev.name.com/core/v1'
            : 'https://api.name.com/core/v1';
        $token = $this->account->credentials['token'] ?? '';
        try {
            $pending = Http::baseUrl($base)->withBasicAuth($this->account->username, $token)->acceptJson()->connectTimeout(10)->timeout(30);
            $response = $method === 'get' ? $pending->get($path, $data) : $pending->post($path, $data);
        } catch (ConnectionException) {
            throw new ProviderException(ErrorCategory::Network, 'Unable to connect to Name.com.');
        }
        if ($response->successful()) {
            return $response;
        }
        $category = match ($response->status()) {
            401 => ErrorCategory::Authentication,
            403 => ErrorCategory::Permission,
            404 => ErrorCategory::DomainNotFound,
            429 => ErrorCategory::RateLimit,
            500, 502, 503, 504 => ErrorCategory::ProviderTemporary,
            400, 405, 415 => ErrorCategory::Validation,
            default => ErrorCategory::Unknown,
        };
        $message = $response->json('message') ?: 'Name.com rejected the request.';
        $retryAfter = is_numeric($response->header('Retry-After')) ? (int) $response->header('Retry-After') : null;
        throw new ProviderException($category, mb_substr(strip_tags($message), 0, 500), (string) $response->status(), $retryAfter);
    }
}
