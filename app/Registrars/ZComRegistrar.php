<?php

namespace App\Registrars;

use App\Enums\ErrorCategory;
use App\Models\RegistrarAccount;
use App\Registrars\Browser\BrowserResult;
use App\Registrars\Browser\PlaywrightRunner;
use App\Registrars\Contracts\Registrar;
use App\Registrars\DTO\ChangeResult;
use App\Registrars\DTO\ConnectionResult;
use App\Registrars\DTO\DomainPage;
use App\Registrars\DTO\RemoteDomain;
use App\Registrars\Exceptions\ProviderException;
use App\Services\NameserverSet;

class ZComRegistrar implements Registrar
{
    public function __construct(
        private readonly RegistrarAccount $account,
        private readonly PlaywrightRunner $runner,
    ) {}

    public function testConnection(): ConnectionResult
    {
        $result = $this->perform('test_connection');

        return new ConnectionResult(true, (string) ($result->data['message'] ?? 'Connection successful.'));
    }

    public function listDomains(int $page = 1): DomainPage
    {
        $result = $this->perform('list_domains', ['page' => $page]);
        $domains = $result->data['domains'] ?? null;

        if (! is_array($domains)) {
            throw new ProviderException(ErrorCategory::ProviderChanged, 'Z.com did not return a recognizable domain list.');
        }

        $remoteDomains = array_map(function ($domain): RemoteDomain {
            if (! is_array($domain) || ! is_string($domain['name'] ?? null) || ! is_array($domain['nameservers'] ?? null)) {
                throw new ProviderException(ErrorCategory::ProviderChanged, 'Z.com returned an invalid domain record.');
            }

            return new RemoteDomain(
                name: NameserverSet::domain($domain['name']),
                nameservers: NameserverSet::normalize($domain['nameservers'], false),
                status: is_string($domain['status'] ?? null) ? $domain['status'] : null,
                tld: is_string($domain['tld'] ?? null) ? $domain['tld'] : NameserverSet::tld($domain['name']),
                renewalPrice: is_numeric($domain['renewal_price'] ?? null) ? (float) $domain['renewal_price'] : null,
                registeredAt: is_string($domain['registered_at'] ?? null) ? $domain['registered_at'] : null,
                expiresAt: is_string($domain['expires_at'] ?? null) ? $domain['expires_at'] : null,
                isLocked: is_bool($domain['is_locked'] ?? null) ? $domain['is_locked'] : null,
                privacyEnabled: is_bool($domain['privacy_enabled'] ?? null) ? $domain['privacy_enabled'] : null,
                autoRenew: is_bool($domain['auto_renew'] ?? null) ? $domain['auto_renew'] : null,
            );
        }, $domains);

        $nextPage = $result->data['next_page'] ?? null;

        return new DomainPage($remoteDomains, is_int($nextPage) ? $nextPage : null);
    }

    public function getNameservers(string $domain): array
    {
        $result = $this->perform('get_nameservers', ['domain' => NameserverSet::domain($domain)]);
        $nameservers = $result->data['nameservers'] ?? null;

        if (! is_array($nameservers)) {
            throw new ProviderException(ErrorCategory::ProviderChanged, 'Z.com did not return recognizable nameservers.');
        }

        return NameserverSet::normalize($nameservers, false);
    }

    public function setNameservers(string $domain, array $nameservers): ChangeResult
    {
        $normalized = NameserverSet::normalize($nameservers);
        $result = $this->perform('set_nameservers', [
            'domain' => NameserverSet::domain($domain),
            'nameservers' => $normalized,
        ]);

        return new ChangeResult((bool) ($result->data['accepted'] ?? false), is_string($result->data['provider_code'] ?? null) ? $result->data['provider_code'] : null);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function perform(string $operation, array $payload = []): BrowserResult
    {
        $result = $this->runner->run($operation, $this->account, $payload);

        if ($result->storageState !== null) {
            $credentials = $this->account->credentials;
            $credentials['storage_state'] = $result->storageState;
            $this->account->update(['credentials' => $credentials]);
        }

        return $result;
    }
}
