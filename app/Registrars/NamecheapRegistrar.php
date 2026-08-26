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
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;

class NamecheapRegistrar implements Registrar
{
    public function __construct(private readonly RegistrarAccount $account) {}

    public function testConnection(): ConnectionResult
    {
        $this->request('namecheap.domains.getList', ['Page' => 1, 'PageSize' => 10]);

        return new ConnectionResult(true, 'Connection successful.');
    }

    public function listDomains(int $page = 1): DomainPage
    {
        $xml = $this->request('namecheap.domains.getList', ['Page' => $page, 'PageSize' => 100]);
        $domains = [];
        foreach ($xml->xpath('//*[local-name()="DomainGetListResult"]/*[local-name()="Domain"]') ?: [] as $node) {
            $name = NameserverSet::domain((string) $node['Name']);
            $domains[] = new RemoteDomain($name, $this->getNameservers($name), (string) ($node['Status'] ?? ''));
        }
        $paging = ($xml->xpath('//*[local-name()="Paging"]') ?: [null])[0];
        $total = $paging ? (int) (($paging->xpath('./*[local-name()="TotalItems"]') ?: [0])[0]) : count($domains);
        $size = $paging ? (int) (($paging->xpath('./*[local-name()="PageSize"]') ?: [100])[0]) : 100;

        return new DomainPage($domains, $page * max($size, 1) < $total ? $page + 1 : null);
    }

    public function getNameservers(string $domain): array
    {
        [$sld, $tld] = $this->splitDomain($domain);
        $xml = $this->request('namecheap.domains.dns.getList', ['SLD' => $sld, 'TLD' => $tld]);
        $nodes = $xml->xpath('//*[local-name()="DomainDNSGetListResult"]/*[local-name()="Nameserver"]') ?: [];

        return NameserverSet::normalize(array_map(fn ($node) => (string) $node, $nodes), false);
    }

    public function setNameservers(string $domain, array $nameservers): ChangeResult
    {
        [$sld, $tld] = $this->splitDomain($domain);
        $xml = $this->request('namecheap.domains.dns.setCustom', [
            'SLD' => $sld, 'TLD' => $tld, 'NameServers' => implode(',', $nameservers),
        ]);
        $result = ($xml->xpath('//*[local-name()="DomainDNSSetCustomResult"]') ?: [null])[0];
        if (! $result || strtolower((string) $result['Updated']) !== 'true') {
            throw new ProviderException(ErrorCategory::ProviderPermanent, 'The registrar did not accept the nameserver change.');
        }

        return new ChangeResult(true);
    }

    private function request(string $command, array $parameters): SimpleXMLElement
    {
        $credentials = $this->account->credentials;
        $query = array_merge([
            'ApiUser' => $this->account->api_user ?: $this->account->username,
            'ApiKey' => $credentials['api_key'] ?? '',
            'UserName' => $this->account->username,
            'ClientIp' => $this->account->client_ipv4,
            'Command' => $command,
        ], $parameters);
        $base = $this->account->environment === RegistrarEnvironment::Sandbox
            ? 'https://api.sandbox.namecheap.com/xml.response'
            : 'https://api.namecheap.com/xml.response';
        try {
            $response = Http::connectTimeout(10)->timeout(30)->get($base, $query);
        } catch (ConnectionException) {
            throw new ProviderException(ErrorCategory::Network, 'Unable to connect to Namecheap.');
        }
        if ($response->serverError()) {
            throw new ProviderException(ErrorCategory::ProviderTemporary, 'Namecheap is temporarily unavailable.', (string) $response->status());
        }
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($response->body(), SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $xml) {
            throw new ProviderException(ErrorCategory::ProviderTemporary, 'Namecheap returned an invalid response.');
        }
        $error = ($xml->xpath('//*[local-name()="Errors"]/*[local-name()="Error"]') ?: [null])[0];
        if (strtoupper((string) $xml['Status']) !== 'OK' || $error) {
            $code = $error ? (string) $error['Number'] : null;
            throw $this->mappedError($code, $error ? (string) $error : 'Namecheap rejected the request.');
        }

        return $xml;
    }

    private function mappedError(?string $code, string $message): ProviderException
    {
        $category = match ($code) {
            '1011150', '1011102' => ErrorCategory::Authentication,
            '2019166' => ErrorCategory::DomainNotFound,
            '2016166' => ErrorCategory::DomainNotOwned,
            '2030166' => ErrorCategory::Permission,
            default => ErrorCategory::ProviderPermanent,
        };

        return new ProviderException($category, mb_substr(strip_tags($message), 0, 500), $code);
    }

    private function splitDomain(string $domain): array
    {
        $parts = explode('.', NameserverSet::domain($domain), 2);
        if (count($parts) !== 2) {
            throw new ProviderException(ErrorCategory::Validation, 'Namecheap requires a registrable domain.');
        }

        return $parts;
    }
}
