<?php

use App\Enums\ErrorCategory;
use App\Enums\RegistrarEnvironment;
use App\Enums\RegistrarProvider;
use App\Models\RegistrarAccount;
use App\Registrars\DynadotRegistrar;
use App\Registrars\Exceptions\ProviderException;
use App\Registrars\InfomaniakRegistrar;
use App\Registrars\NameSiloRegistrar;
use App\Registrars\PorkbunRegistrar;
use App\Registrars\RegistrarFactory;
use App\Registrars\SpaceshipRegistrar;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

test('namesilo adapter lists domains and changes nameservers', function () {
    $account = registrarAccount(RegistrarProvider::NameSilo, RegistrarEnvironment::Sandbox, ['api_key' => 'key']);
    Http::preventStrayRequests();
    Http::fake([
        'sandbox.namesilo.com/api/listDomains*' => Http::response(['reply' => [
            'code' => 300,
            'domains' => ['domain' => [['domain' => 'Example.COM']]],
            'totalDomains' => 1,
        ]]),
        'sandbox.namesilo.com/api/getDomainInfo*' => Http::response(['reply' => [
            'code' => 300,
            'status' => 'Active',
            'created' => '2024-01-01',
            'expires' => '2027-01-01',
            'locked' => ['value' => 1],
            'private' => ['value' => 1],
            'auto_renew' => ['value' => 0],
            'nameservers' => [
                ['nameserver' => 'NS1.EXAMPLE.COM.'],
                ['nameserver' => 'ns2.example.com'],
            ],
        ]]),
        'sandbox.namesilo.com/api/changeNameServers*' => Http::response(['reply' => ['code' => 300, 'detail' => 'success']]),
    ]);

    $registrar = new NameSiloRegistrar($account);
    $page = $registrar->listDomains();
    $change = $registrar->setNameservers('example.com', ['NS3.EXAMPLE.COM.', 'ns4.example.com']);

    expect($page->domains[0]->name)->toBe('example.com')
        ->and($page->domains[0]->nameservers)->toBe(['ns1.example.com', 'ns2.example.com'])
        ->and($page->domains[0]->isLocked)->toBeTrue()
        ->and($page->domains[0]->privacyEnabled)->toBeTrue()
        ->and($page->domains[0]->autoRenew)->toBeFalse()
        ->and($page->nextPage)->toBeNull()
        ->and($change->accepted)->toBeTrue();
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'changeNameServers')
        && $request->data()['ns1'] === 'ns3.example.com'
        && $request->data()['ns2'] === 'ns4.example.com');
});

test('dynadot adapter follows api3 pagination and nameserver commands', function () {
    $account = registrarAccount(RegistrarProvider::Dynadot, RegistrarEnvironment::Sandbox, ['api_key' => 'key']);
    Http::preventStrayRequests();
    Sleep::fake();
    Http::fake(function (Request $request) {
        return match ($request->data()['command'] ?? null) {
            'list_domain' => Http::response(['ListDomainInfoResponse' => [
                'ResponseCode' => 0,
                'Status' => 'success',
                'TotalCount' => 2,
                'MainDomains' => [[
                    'Name' => 'Example.COM',
                    'Registration' => '1704067200000',
                    'Expiration' => '1798761600000',
                    'Locked' => 'yes',
                    'Disabled' => 'no',
                    'NameServerSettings' => ['Host0' => 'NS1.EXAMPLE.COM.', 'Host1' => 'ns2.example.com'],
                ], [
                    'Name' => 'parked-example.com',
                    'Registration' => '1704067200000',
                    'Expiration' => '1798761600000',
                    'Locked' => 'no',
                    'Disabled' => 'no',
                    'NameServerSettings' => ['Type' => 'Dynadot Parking', 'WithAds' => 'Yes'],
                ]],
            ]]),
            'get_ns' => Http::response(['GetNsResponse' => ['ResponseCode' => 0, 'Status' => 'success', 'NsContent' => ['Host0' => 'ns1.example.com', 'Host1' => 'ns2.example.com']]]),
            'set_ns' => Http::response(['SetNsResponse' => ['ResponseCode' => 0, 'Status' => 'success']]),
            'tld_price' => Http::response(['TldPriceResponse' => ['ResponseCode' => 0, 'Status' => 'success', 'TldPrice' => []]]),
            default => Http::response([], 500),
        };
    });

    $registrar = new DynadotRegistrar($account);
    $page = $registrar->listDomains();

    expect($page->domains[0]->name)->toBe('example.com')
        ->and($page->domains[0]->nameservers)->toBe(['ns1.example.com', 'ns2.example.com'])
        ->and($page->domains[0]->isLocked)->toBeTrue()
        ->and($page->domains[0]->status)->toBe('ACTIVE')
        ->and($page->domains[1]->nameservers)->toBe(['ns1.example.com', 'ns2.example.com'])
        ->and($registrar->getNameservers('example.com'))->toBe(['ns1.example.com', 'ns2.example.com'])
        ->and($registrar->setNameservers('example.com', ['ns3.example.com', 'ns4.example.com'])->accepted)->toBeTrue();
    Http::assertSent(fn (Request $request): bool => ($request->data()['command'] ?? null) === 'set_ns'
        && $request->data()['ns0'] === 'ns3.example.com'
        && $request->data()['ns1'] === 'ns4.example.com');
    Sleep::assertSequence([Sleep::for(1)->second()]);
});

test('dynadot adapter normalizes domain settings and fetches missing nameservers', function () {
    $account = registrarAccount(RegistrarProvider::Dynadot, RegistrarEnvironment::Sandbox, ['api_key' => 'key']);
    Http::preventStrayRequests();
    Sleep::fake();
    Http::fake(function (Request $request) {
        return match ($request->data()['command'] ?? null) {
            'list_domain' => Http::response(['ListDomainInfoResponse' => [
                'ResponseCode' => 0,
                'Status' => 'success',
                'TotalCount' => 3,
                'MainDomains' => [[
                    'Name' => 'full-privacy.example',
                    'Privacy' => 'full',
                    'RenewOption' => 'auto renewal',
                    'NameServerSettings' => [
                        'Type' => 'Name Servers',
                        'NameServers' => [
                            ['ServerId' => '1', 'ServerName' => 'ns1.example.com'],
                            ['ServerId' => '2', 'ServerName' => 'ns2.example.com'],
                        ],
                    ],
                ], [
                    'Name' => 'partial-privacy.example',
                    'Privacy' => 'partial',
                    'RenewOption' => 'do not renew',
                    'NameServerSettings' => ['Type' => 'Dynadot DNS'],
                ], [
                    'Name' => 'privacy-off.example',
                    'Privacy' => 'off',
                    'RenewOption' => 'manual renewal',
                    'NameServerSettings' => [
                        'Type' => 'Name Servers',
                        'NameServers' => [
                            ['ServerId' => '3', 'ServerName' => 'ns3.example.com'],
                            ['ServerId' => '4', 'ServerName' => 'ns4.example.com'],
                        ],
                    ],
                ]],
            ]]),
            'get_ns' => Http::response(['GetNsResponse' => [
                'ResponseCode' => 0,
                'Status' => 'success',
                'NsContent' => ['Host0' => 'ns5.example.com', 'Host1' => 'ns6.example.com'],
            ]]),
            'tld_price' => Http::response(['TldPriceResponse' => ['ResponseCode' => 0, 'Status' => 'success', 'TldPrice' => []]]),
            default => Http::response([], 500),
        };
    });

    $page = (new DynadotRegistrar($account))->listDomains();

    expect($page->domains[0]->privacyEnabled)->toBeTrue()
        ->and($page->domains[0]->autoRenew)->toBeTrue()
        ->and($page->domains[0]->nameservers)->toBe(['ns1.example.com', 'ns2.example.com'])
        ->and($page->domains[1]->privacyEnabled)->toBeTrue()
        ->and($page->domains[1]->autoRenew)->toBeFalse()
        ->and($page->domains[1]->nameservers)->toBe(['ns5.example.com', 'ns6.example.com'])
        ->and($page->domains[2]->privacyEnabled)->toBeFalse()
        ->and($page->domains[2]->autoRenew)->toBeFalse()
        ->and($page->domains[2]->nameservers)->toBe(['ns3.example.com', 'ns4.example.com']);
    Http::assertSent(fn (Request $request): bool => ($request->data()['command'] ?? null) === 'get_ns'
        && $request->data()['domain'] === 'partial-privacy.example');
    Http::assertSentCount(3);
    Sleep::assertSequence([Sleep::for(1)->second()]);
});

test('dynadot adapter maps renewal prices by tld once', function () {
    $account = registrarAccount(RegistrarProvider::Dynadot, RegistrarEnvironment::Sandbox, ['api_key' => 'key']);
    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        return match ($request->data()['command'] ?? null) {
            'list_domain' => Http::response(['ListDomainInfoResponse' => [
                'ResponseCode' => 0,
                'Status' => 'success',
                'TotalCount' => 3,
                'MainDomains' => [[
                    'Name' => 'example.com',
                    'NameServerSettings' => ['Host0' => 'ns1.example.com', 'Host1' => 'ns2.example.com'],
                ], [
                    'Name' => 'example.co.uk',
                    'NameServerSettings' => ['Host0' => 'ns1.example.com', 'Host1' => 'ns2.example.com'],
                ], [
                    'Name' => 'example.invalid',
                    'NameServerSettings' => ['Host0' => 'ns1.example.com', 'Host1' => 'ns2.example.com'],
                ]],
            ]]),
            'tld_price' => Http::response(['TldPriceResponse' => [
                'ResponseCode' => 0,
                'Status' => 'success',
                'TldPrice' => [[
                    'Tld' => 'com',
                    'Price' => ['Renew' => '12.50'],
                ], [
                    'Tld' => '.co.uk',
                    'Price' => ['Renew' => '9.75'],
                ], [
                    'Tld' => 'invalid',
                    'Price' => ['Renew' => 'not-available'],
                ]],
            ]]),
            default => Http::response([], 500),
        };
    });

    $page = (new DynadotRegistrar($account))->listDomains();

    expect($page->domains[0]->renewalPrice)->toBe(12.5)
        ->and($page->domains[1]->renewalPrice)->toBe(9.75)
        ->and($page->domains[2]->renewalPrice)->toBeNull();
    Http::assertSent(fn (Request $request): bool => ($request->data()['command'] ?? null) === 'tld_price'
        && $request->data()['currency'] === 'USD'
        && $request->data()['count_per_page'] === 1000
        && $request->data()['page_index'] === 0);
    Http::assertSentCount(2);
});

test('porkbun adapter uses paired headers and supported nameserver endpoints', function () {
    $account = registrarAccount(RegistrarProvider::Porkbun, RegistrarEnvironment::Sandbox, [
        'api_key' => 'public-key',
        'secret_api_key' => 'secret-key',
    ]);
    Http::preventStrayRequests();
    Http::fake([
        'api.porkbun.com/api/json/v3/domain/listAll*' => Http::response(['status' => 'SUCCESS', 'total' => 2, 'domains' => [
            [
                'domain' => 'Example.COM',
                'status' => 'ACTIVE',
                'createDate' => '2024-01-01 00:00:00',
                'expireDate' => '2027-01-01 00:00:00',
                'securityLock' => 1,
                'whoisPrivacy' => 1,
                'autoRenew' => 0,
            ],
            [
                'domain' => 'second-example.com',
                'status' => 'ACTIVE',
                'createDate' => '2024-02-01 00:00:00',
                'expireDate' => '2027-02-01 00:00:00',
                'securityLock' => 0,
                'whoisPrivacy' => 0,
                'autoRenew' => 1,
            ],
        ]]),
        'api.porkbun.com/api/json/v3/domain/getNs/*' => Http::response(['status' => 'SUCCESS', 'ns' => ['NS1.EXAMPLE.COM.', 'ns2.example.com']]),
        'api.porkbun.com/api/json/v3/domain/updateNs/*' => Http::response(['status' => 'SUCCESS']),
    ]);

    $registrar = new PorkbunRegistrar($account);
    $page = $registrar->listDomains();
    $registrar->setNameservers('example.com', ['ns3.example.com', 'ns4.example.com']);

    expect($page->domains[0]->nameservers)->toBe(['ns1.example.com', 'ns2.example.com'])
        ->and($page->domains[1]->nameservers)->toBe(['ns1.example.com', 'ns2.example.com'])
        ->and($page->domains[0]->privacyEnabled)->toBeTrue()
        ->and($page->domains[0]->autoRenew)->toBeFalse();
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-API-Key', 'public-key')
        && $request->hasHeader('X-Secret-API-Key', 'secret-key'));
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/updateNs/')
        && $request['ns'] === ['ns3.example.com', 'ns4.example.com']);
    Http::assertSentCount(4);
});

test('spaceship adapter maps embedded nameservers and updates custom hosts', function () {
    $account = registrarAccount(RegistrarProvider::Spaceship, RegistrarEnvironment::Production, [
        'api_key' => 'public-key',
        'api_secret' => 'secret-key',
    ]);
    Http::preventStrayRequests();
    Http::fake([
        'spaceship.dev/api/v1/domains?*' => Http::response(['total' => 1, 'items' => [[
            'name' => 'Example.COM',
            'autoRenew' => true,
            'registrationDate' => '2024-01-01T00:00:00Z',
            'expirationDate' => '2027-01-01T00:00:00Z',
            'renewalPrice' => ['amount' => '9.98', 'currency' => 'USD'],
            'lifecycleStatus' => 'active',
            'eppStatuses' => ['clientTransferProhibited'],
            'privacyProtection' => ['level' => 'high'],
            'nameservers' => ['provider' => 'custom', 'hosts' => ['NS1.EXAMPLE.COM.', 'ns2.example.com']],
        ]]]),
        'spaceship.dev/api/v1/domains/*/nameservers' => Http::response([], 204),
    ]);

    $registrar = new SpaceshipRegistrar($account);
    $page = $registrar->listDomains();
    $registrar->setNameservers('example.com', ['ns3.example.com', 'ns4.example.com']);

    expect($page->domains[0]->status)->toBe('ACTIVE')
        ->and($page->domains[0]->nameservers)->toBe(['ns1.example.com', 'ns2.example.com'])
        ->and($page->domains[0]->renewalPrice)->toBe(9.98)
        ->and($page->domains[0]->isLocked)->toBeTrue()
        ->and($page->domains[0]->privacyEnabled)->toBeTrue()
        ->and($page->domains[0]->autoRenew)->toBeTrue();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request['provider'] === 'custom'
        && $request['hosts'] === ['ns3.example.com', 'ns4.example.com']);
});

test('infomaniak adapter syncs inventory and reports unsupported delegation writes', function () {
    $account = registrarAccount(RegistrarProvider::Infomaniak, RegistrarEnvironment::Production, ['token' => 'token']);
    Http::preventStrayRequests();
    Http::fake([
        'api.infomaniak.com/2/domains/domains*' => Http::response([
            'result' => 'success',
            'data' => [[
                'name' => 'Example.COM',
                'tld' => 'com',
                'created_at' => 1704067200,
                'expires_at' => 1798761600,
                'renewal_price' => ['amount' => '14.90', 'currency' => 'CHF'],
                'locked' => 'true',
                'options' => ['domain_privacy' => 'true', 'renewal_warranty' => 'false'],
            ]],
            'page' => 1,
            'pages' => 2,
        ]),
        'api.infomaniak.com/2/zones/*' => Http::response(['result' => 'success', 'data' => ['nameservers' => ['NS1.EXAMPLE.COM.', 'ns2.example.com']]]),
    ]);

    $registrar = new InfomaniakRegistrar($account);
    $page = $registrar->listDomains();

    expect($page->domains[0]->name)->toBe('example.com')
        ->and($page->domains[0]->nameservers)->toBe(['ns1.example.com', 'ns2.example.com'])
        ->and($page->domains[0]->renewalPrice)->toBe(14.9)
        ->and($page->domains[0]->isLocked)->toBeTrue()
        ->and($page->domains[0]->privacyEnabled)->toBeTrue()
        ->and($page->domains[0]->autoRenew)->toBeFalse()
        ->and($page->nextPage)->toBe(2);

    try {
        $registrar->setNameservers('example.com', ['ns3.example.com', 'ns4.example.com']);
        $this->fail('Expected an unsupported-operation exception.');
    } catch (ProviderException $exception) {
        expect($exception->category)->toBe(ErrorCategory::ProviderPermanent)
            ->and($exception->getMessage())->toContain('does not support');
    }
});

test('registrar factory resolves all newly supported adapters', function (RegistrarProvider $provider, string $adapter) {
    $account = registrarAccount($provider, RegistrarEnvironment::Production, ['api_key' => 'key', 'token' => 'token']);

    expect(app(RegistrarFactory::class)->for($account))->toBeInstanceOf($adapter);
})->with([
    'NameSilo' => [RegistrarProvider::NameSilo, NameSiloRegistrar::class],
    'Dynadot' => [RegistrarProvider::Dynadot, DynadotRegistrar::class],
    'Porkbun' => [RegistrarProvider::Porkbun, PorkbunRegistrar::class],
    'Spaceship' => [RegistrarProvider::Spaceship, SpaceshipRegistrar::class],
    'Infomaniak' => [RegistrarProvider::Infomaniak, InfomaniakRegistrar::class],
]);

/** @param array<string, string> $credentials */
function registrarAccount(RegistrarProvider $provider, RegistrarEnvironment $environment, array $credentials): RegistrarAccount
{
    return RegistrarAccount::create([
        'provider' => $provider,
        'environment' => $environment,
        'label' => $provider->value.' Test',
        'username' => 'operator',
        'credentials' => $credentials,
        'is_active' => true,
    ]);
}
