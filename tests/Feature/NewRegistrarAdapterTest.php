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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

test('namesilo adapter lists domains and changes nameservers', function () {
    $account = registrarAccount(RegistrarProvider::NameSilo, RegistrarEnvironment::Sandbox, ['api_key' => 'key']);
    Http::preventStrayRequests();
    Http::fake([
        'sandbox.namesilo.com/apibatch/listDomains*' => Http::response(['reply' => [
            'code' => 300,
            'domains' => ['domain' => [['domain' => 'Example.COM']]],
            'totalDomains' => 1,
        ]]),
        'sandbox.namesilo.com/apibatch/getDomainInfo*' => Http::response(['reply' => [
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
        'sandbox.namesilo.com/apibatch/getPrices*' => Http::response(['reply' => [
            'code' => 300,
            'detail' => 'success',
            'com' => ['registration' => 10.00, 'transfer' => 10.40, 'renew' => 11.95],
            'net' => ['registration' => 11.85, 'transfer' => 11.35, 'renew' => 11.85],
        ]]),
        'sandbox.namesilo.com/api/changeNameServers*' => Http::response(['reply' => ['code' => 300, 'detail' => 'success']]),
    ]);

    $registrar = new NameSiloRegistrar($account);
    $page = $registrar->listDomains();
    $renewalPrices = $registrar->renewalPrices(['com']);
    $change = $registrar->setNameservers('example.com', ['NS3.EXAMPLE.COM.', 'ns4.example.com']);

    expect($page->domains[0]->name)->toBe('example.com')
        ->and($page->domains[0]->nameservers)->toBe(['ns1.example.com', 'ns2.example.com'])
        ->and($page->domains[0]->isLocked)->toBeTrue()
        ->and($page->domains[0]->privacyEnabled)->toBeTrue()
        ->and($page->domains[0]->autoRenew)->toBeFalse()
        ->and($page->nextPage)->toBeNull()
        ->and($renewalPrices)->toBe(['com' => 11.95])
        ->and($change->accepted)->toBeTrue();
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/apibatch/getPrices')
        && $request['type'] === 'json'
        && $request['version'] === 1);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'changeNameServers')
        && $request->data()['ns1'] === 'ns3.example.com'
        && $request->data()['ns2'] === 'ns4.example.com');
});

test('namesilo adapter preserves missing renewal prices and logs their tlds', function () {
    $account = registrarAccount(RegistrarProvider::NameSilo, RegistrarEnvironment::Sandbox, ['api_key' => 'key']);
    Log::spy();
    Http::preventStrayRequests();
    Http::fake([
        'sandbox.namesilo.com/apibatch/getPrices*' => Http::response(['reply' => [
            'code' => 300,
            'detail' => 'success',
            'com' => ['renew' => '11.95'],
            'xyz' => ['renew' => 'not-available'],
        ]]),
    ]);

    $renewalPrices = (new NameSiloRegistrar($account))->renewalPrices(['com', 'xyz', 'missing']);

    expect($renewalPrices)->toBe(['com' => 11.95]);
    Log::shouldHaveReceived('warning')->once()->with(
        'NameSilo renewal prices were missing from the pricing response.',
        Mockery::on(fn (array $context): bool => $context['registrar_account_id'] === $account->id
            && $context['tlds'] === ['xyz', 'missing']),
    );
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
    $nameservers = $registrar->getNameservers('example.com');
    $registrar->setNameservers('example.com', ['ns3.example.com', 'ns4.example.com']);

    expect($page->domains[0]->nameservers)->toBe([])
        ->and($page->domains[1]->nameservers)->toBe([])
        ->and($nameservers)->toBe(['ns1.example.com', 'ns2.example.com'])
        ->and($page->domains[0]->privacyEnabled)->toBeTrue()
        ->and($page->domains[0]->autoRenew)->toBeFalse();
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-API-Key', 'public-key')
        && $request->hasHeader('X-Secret-API-Key', 'secret-key'));
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/updateNs/')
        && $request['ns'] === ['ns3.example.com', 'ns4.example.com']);
    Http::assertSentCount(3);
});

test('porkbun adapter lists inventory without per-domain nameserver requests and fetches renewal prices once', function () {
    $account = registrarAccount(RegistrarProvider::Porkbun, RegistrarEnvironment::Production, [
        'api_key' => 'public-key',
        'secret_api_key' => 'secret-key',
    ]);
    Http::preventStrayRequests();
    Http::fake([
        'api.porkbun.com/api/json/v3/domain/listAll*' => Http::response([
            'status' => 'SUCCESS',
            'total' => 1,
            'domains' => [['domain' => 'fastcredit24.com']],
        ]),
        'api.porkbun.com/api/json/v3/pricing/get*' => Http::response([
            'status' => 'SUCCESS',
            'pricing' => ['com' => ['renewal' => '11.25'], 'net' => ['renewal' => '12.50']],
        ]),
    ]);

    $registrar = new PorkbunRegistrar($account);
    $page = $registrar->listDomains();
    $prices = $registrar->renewalPrices(['com']);

    expect($page->domains[0]->nameservers)->toBe([])
        ->and($prices)->toBe(['com' => 11.25]);
    Http::assertSentCount(2);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/domain/getNs/'));
});

test('porkbun adapter reports structured provider errors with actionable diagnostics', function () {
    $account = registrarAccount(RegistrarProvider::Porkbun, RegistrarEnvironment::Production, [
        'api_key' => 'public-key',
        'secret_api_key' => 'secret-key',
    ]);
    Log::spy();
    Http::preventStrayRequests();
    Http::fake([
        'api.porkbun.com/api/json/v3/domain/listAll*' => Http::response([
            'status' => 'SUCCESS',
            'total' => 1,
            'domains' => [['domain' => 'lgwinesmart-event.com']],
        ]),
        'api.porkbun.com/api/json/v3/domain/getNs/*' => Http::response([
            'status' => 'ERROR',
            'message' => 'This API key is not allowed to access this domain.',
            'code' => 'DOMAIN_NOT_ALLOWED',
            'requestId' => 'request-123',
            'next_action' => [
                'type' => 'enable_setting',
                'hint' => 'Add the domain to this API key allowlist.',
                'retryable' => false,
                'url' => 'https://porkbun.com/account/api',
            ],
        ], 403, [
            'Content-Type' => 'application/json',
            'X-API-Version' => '3.17',
            'X-Request-Id' => 'request-123',
        ]),
    ]);

    $exception = null;
    try {
        (new PorkbunRegistrar($account))->getNameservers('lgwinesmart-event.com');
    } catch (ProviderException $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(ProviderException::class)
        ->and($exception?->category)->toBe(ErrorCategory::Permission)
        ->and($exception?->providerCode)->toBe('DOMAIN_NOT_ALLOWED')
        ->and($exception?->getMessage())->toContain('DOMAIN_NOT_ALLOWED')
        ->and($exception?->getMessage())->toContain('Add the domain to this API key allowlist.')
        ->and($exception?->getMessage())->toContain('request-123');
    Log::shouldHaveReceived('error')->once()->with(
        'Porkbun API request failed.',
        Mockery::on(function (array $context) use ($account): bool {
            $serializedContext = json_encode($context);

            return $context['registrar_account_id'] === $account->id
                && $context['endpoint'] === '/domain/getNs/lgwinesmart-event.com'
                && $context['http_status'] === 403
                && $context['provider_code'] === 'DOMAIN_NOT_ALLOWED'
                && $context['request_id'] === 'request-123'
                && $context['next_action_type'] === 'enable_setting'
                && $context['next_action_retryable'] === false
                && $context['api_version'] === '3.17'
                && is_string($serializedContext)
                && ! str_contains($serializedContext, 'public-key')
                && ! str_contains($serializedContext, 'secret-key');
        }),
    );
});

test('porkbun adapter logs an excerpt when the provider returns a non-json error', function () {
    $account = registrarAccount(RegistrarProvider::Porkbun, RegistrarEnvironment::Production, [
        'api_key' => 'public-key',
        'secret_api_key' => 'secret-key',
    ]);
    Log::spy();
    Http::preventStrayRequests();
    Http::fake([
        'api.porkbun.com/api/json/v3/domain/listAll*' => Http::response([
            'status' => 'SUCCESS',
            'total' => 1,
            'domains' => [['domain' => 'lgwinesmart-event.com']],
        ]),
        'api.porkbun.com/api/json/v3/domain/getNs/*' => Http::response(
            '<html><body>Access denied by upstream security policy. public-key secret-key</body></html>',
            403,
            ['Content-Type' => 'text/html', 'X-Request-Id' => 'edge-request-456'],
        ),
    ]);

    $exception = null;
    try {
        (new PorkbunRegistrar($account))->getNameservers('lgwinesmart-event.com');
    } catch (ProviderException $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(ProviderException::class)
        ->and($exception?->category)->toBe(ErrorCategory::Permission)
        ->and($exception?->providerCode)->toBe('HTTP_403')
        ->and($exception?->getMessage())->toContain('HTTP 403')
        ->and($exception?->getMessage())->toContain('non-JSON response')
        ->and($exception?->getMessage())->toContain('edge-request-456');
    Log::shouldHaveReceived('error')->once()->with(
        'Porkbun API request failed.',
        Mockery::on(fn (array $context): bool => $context['response_format'] === 'non_json'
            && $context['response_body_excerpt'] === 'Access denied by upstream security policy. [REDACTED] [REDACTED]'
            && $context['request_id'] === 'edge-request-456'),
    );
});

test('spaceship adapter maps embedded nameservers and updates custom hosts', function () {
    $account = registrarAccount(RegistrarProvider::Spaceship, RegistrarEnvironment::Production, [
        'api_key' => 'public-key',
        'api_secret' => 'secret-key',
    ]);
    config()->set('services.spaceship.pricing_reader_url', 'https://pricing-reader.test/http://www.spaceship.com');
    Http::preventStrayRequests();
    Http::fake([
        'spaceship.dev/api/v1/domains?*' => Http::response(['total' => 1, 'items' => [[
            'name' => 'Example.COM',
            'autoRenew' => true,
            'registrationDate' => '2024-01-01T00:00:00Z',
            'expirationDate' => '2027-01-01T00:00:00Z',
            'lifecycleStatus' => 'active',
            'eppStatuses' => ['clientTransferProhibited'],
            'privacyProtection' => ['level' => 'high'],
            'nameservers' => ['provider' => 'custom', 'hosts' => ['NS1.EXAMPLE.COM.', 'ns2.example.com']],
        ]]]),
        'spaceship.dev/api/v1/domains/*/nameservers' => Http::response([], 204),
        'pricing-reader.test/http://www.spaceship.com/domains/gtld/com/' => Http::response(<<<'MARKDOWN'
            | .com | 1 year | 2 years |
            | --- | --- | --- |
            | Register | $8.88/yr | $18.54/yr |
            | Renew | $9.98/yr | $19.96/yr |
            MARKDOWN),
    ]);

    $registrar = new SpaceshipRegistrar($account);
    $page = $registrar->listDomains();
    $renewalPrices = $registrar->renewalPrices(['.COM']);
    $cachedRenewalPrices = $registrar->renewalPrices(['com']);
    $registrar->setNameservers('example.com', ['ns3.example.com', 'ns4.example.com']);

    expect($page->domains[0]->status)->toBe('ACTIVE')
        ->and($page->domains[0]->nameservers)->toBe(['ns1.example.com', 'ns2.example.com'])
        ->and($page->domains[0]->renewalPrice)->toBeNull()
        ->and($renewalPrices)->toBe(['com' => 9.98])
        ->and($cachedRenewalPrices)->toBe(['com' => 9.98])
        ->and($page->domains[0]->isLocked)->toBeTrue()
        ->and($page->domains[0]->privacyEnabled)->toBeTrue()
        ->and($page->domains[0]->autoRenew)->toBeTrue();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request['provider'] === 'custom'
        && $request['hosts'] === ['ns3.example.com', 'ns4.example.com']);
    Http::assertSentCount(3);
});

test('spaceship adapter keeps successful renewal prices when another tld pricing page fails', function () {
    $account = registrarAccount(RegistrarProvider::Spaceship, RegistrarEnvironment::Production, [
        'api_key' => 'public-key',
        'api_secret' => 'secret-key',
    ]);
    config()->set('services.spaceship.pricing_reader_url', 'https://pricing-reader.test/http://www.spaceship.com');
    Log::spy();
    Http::preventStrayRequests();
    Http::fake([
        'pricing-reader.test/http://www.spaceship.com/domains/gtld/com/' => Http::response("Renew\n\n\$9.98/yr"),
        'pricing-reader.test/http://www.spaceship.com/domains/cctld/co-uk/' => Http::response('Pricing unavailable', 503),
    ]);

    $renewalPrices = (new SpaceshipRegistrar($account))->renewalPrices(['com', 'co.uk']);

    expect($renewalPrices)->toBe(['com' => 9.98]);
    Log::shouldHaveReceived('warning')->once()->with(
        'Spaceship renewal prices were missing from the public pricing source.',
        Mockery::on(fn (array $context): bool => $context['registrar_account_id'] === $account->id
            && $context['failures'] === ['co.uk' => 'HTTP 503']),
    );
});

test('infomaniak adapter syncs official renewal and lock fields and updates nameservers', function () {
    $account = registrarAccount(RegistrarProvider::Infomaniak, RegistrarEnvironment::Production, ['token' => 'token']);
    Http::preventStrayRequests();
    Http::fake([
        'api.infomaniak.com/2/domains/domains/example.com/nameservers' => Http::response([
            'result' => 'success',
            'data' => true,
        ]),
        'api.infomaniak.com/2/domains/domains*' => Http::response([
            'result' => 'success',
            'data' => [[
                'name' => 'Example.COM',
                'tld' => 'com',
                'created_at' => 1704067200,
                'expires_at' => 1798761600,
                'status' => ['ok', 'clientTransferProhibited'],
                'options' => ['domain_privacy' => 'true', 'renewal_warranty' => 'false'],
            ]],
            'page' => 1,
            'pages' => 2,
        ]),
        'api.infomaniak.com/2/zones/*' => Http::response(['result' => 'success', 'data' => ['nameservers' => ['NS1.EXAMPLE.COM.', 'ns2.example.com']]]),
        'www.infomaniak.com/api-g/tldprice*' => Http::response([
            'data' => [
                'aSellPricesDiscounted' => [
                    'CHF' => ['fRenewExclTax' => '13.60', 'fRenewInclTax' => '14.70'],
                ],
            ],
        ]),
    ]);

    $registrar = new InfomaniakRegistrar($account);
    $page = $registrar->listDomains();
    $nameservers = $registrar->getNameservers('example.com');
    $renewalPrices = $registrar->renewalPrices(['com']);
    $result = $registrar->setNameservers('Example.COM', ['NS3.EXAMPLE.COM.', 'ns4.example.com']);

    expect($page->domains[0]->name)->toBe('example.com')
        ->and($page->domains[0]->nameservers)->toBe([])
        ->and($page->domains[0]->renewalPrice)->toBeNull()
        ->and($nameservers)->toBe(['ns1.example.com', 'ns2.example.com'])
        ->and($renewalPrices)->toBe(['com' => 13.6])
        ->and($page->domains[0]->isLocked)->toBeTrue()
        ->and($page->domains[0]->privacyEnabled)->toBeTrue()
        ->and($page->domains[0]->autoRenew)->toBeFalse()
        ->and($page->nextPage)->toBe(2)
        ->and($result->accepted)->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_contains($request->url(), 'www.infomaniak.com/api-g/tldprice')
        && $request['country'] === 'CH'
        && $request['ext'] === 'com');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->url() === 'https://api.infomaniak.com/2/domains/domains/example.com/nameservers'
        && $request['nameservers'] === ['ns3.example.com', 'ns4.example.com']);
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
