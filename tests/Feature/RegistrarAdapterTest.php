<?php

use App\Enums\RegistrarEnvironment;
use App\Enums\RegistrarProvider;
use App\Models\RegistrarAccount;
use App\Registrars\Browser\BrowserResult;
use App\Registrars\Browser\PlaywrightRunner;
use App\Registrars\NamecheapRegistrar;
use App\Registrars\NameComRegistrar;
use App\Registrars\RegistrarFactory;
use App\Registrars\ZComRegistrar;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('namecom adapter follows the core api response shape', function () {
    $account = RegistrarAccount::create(['provider' => RegistrarProvider::NameCom, 'environment' => RegistrarEnvironment::Sandbox, 'label' => 'Name.com', 'username' => 'user-test', 'credentials' => ['token' => 'token'], 'is_active' => true]);
    Http::fake([
        'api.dev.name.com/core/v1/domains?*' => Http::response(['domains' => [['domainName' => 'Example.COM', 'nameservers' => ['NS1.EXAMPLE.COM.', 'ns2.example.com'], 'locked' => true]], 'nextPage' => 2]),
        'api.dev.name.com/core/v1/domains/example.com:setNameservers' => Http::response(['domainName' => 'example.com'], 200),
    ]);
    $registrar = new NameComRegistrar($account);
    $page = $registrar->listDomains();
    expect($page->domains[0]->name)->toBe('example.com')->and($page->domains[0]->nameservers)->toBe(['ns1.example.com', 'ns2.example.com'])->and($page->nextPage)->toBe(2);
    $registrar->setNameservers('example.com', ['ns1.example.com', 'ns2.example.com']);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), ':setNameservers') && $request['nameservers'] === ['ns1.example.com', 'ns2.example.com']);
});

test('namecheap adapter parses namespaced xml and splits multipart tlds', function () {
    $account = RegistrarAccount::create(['provider' => RegistrarProvider::Namecheap, 'environment' => RegistrarEnvironment::Sandbox, 'label' => 'Namecheap', 'username' => 'user', 'api_user' => 'api-user', 'client_ipv4' => '192.0.2.10', 'credentials' => ['api_key' => 'secret'], 'is_active' => true]);
    $list = '<?xml version="1.0"?><ApiResponse xmlns="http://api.namecheap.com/xml.response" Status="OK"><Errors/><CommandResponse><DomainGetListResult><Domain Name="Example.CO.UK" Status="ok"/></DomainGetListResult><Paging><TotalItems>1</TotalItems><CurrentPage>1</CurrentPage><PageSize>100</PageSize></Paging></CommandResponse></ApiResponse>';
    $dns = '<?xml version="1.0"?><ApiResponse xmlns="http://api.namecheap.com/xml.response" Status="OK"><Errors/><CommandResponse><DomainDNSGetListResult Domain="example.co.uk"><Nameserver>NS1.EXAMPLE.COM.</Nameserver><Nameserver>ns2.example.com</Nameserver></DomainDNSGetListResult></CommandResponse></ApiResponse>';
    Http::fakeSequence()->push($list, 200)->push($dns, 200);
    $page = (new NamecheapRegistrar($account))->listDomains();
    expect($page->domains[0]->name)->toBe('example.co.uk')->and($page->domains[0]->nameservers)->toBe(['ns1.example.com', 'ns2.example.com']);
    Http::assertSent(fn (Request $request) => ! str_contains($request->url(), 'dns.getList') || ($request->data()['SLD'] === 'example' && $request->data()['TLD'] === 'co.uk'));
});

test('namecheap connection test uses the documented minimum page size', function () {
    $account = RegistrarAccount::create(['provider' => RegistrarProvider::Namecheap, 'environment' => RegistrarEnvironment::Sandbox, 'label' => 'Namecheap Test', 'username' => 'user', 'client_ipv4' => '192.0.2.10', 'credentials' => ['api_key' => 'secret'], 'is_active' => true]);
    $response = '<?xml version="1.0"?><ApiResponse xmlns="http://api.namecheap.com/xml.response" Status="OK"><Errors/><CommandResponse><DomainGetListResult/><Paging><TotalItems>0</TotalItems><CurrentPage>1</CurrentPage><PageSize>10</PageSize></Paging></CommandResponse></ApiResponse>';
    Http::fake(['*' => Http::response($response, 200)]);

    expect((new NamecheapRegistrar($account))->testConnection()->successful)->toBeTrue();
    Http::assertSent(fn (Request $request) => $request->data()['Page'] === 1 && $request->data()['PageSize'] === 10);
});

test('zcom adapter maps browser results and persists refreshed session state', function () {
    $account = RegistrarAccount::create([
        'provider' => RegistrarProvider::ZCom,
        'environment' => RegistrarEnvironment::Production,
        'label' => 'Z.com',
        'username' => 'owner@example.com',
        'credentials' => ['password' => 'password'],
        'is_active' => true,
    ]);
    $runner = Mockery::mock(PlaywrightRunner::class);
    $runner->shouldReceive('run')->once()->with('list_domains', $account, ['page' => 1])->andReturn(new BrowserResult([
        'domains' => [[
            'name' => 'Example.COM',
            'nameservers' => ['NS1.EXAMPLE.COM.', 'ns2.example.com'],
            'status' => 'ACTIVE',
        ]],
        'next_page' => null,
    ], ['cookies' => [['name' => 'session']]]));
    $runner->shouldReceive('run')->once()->with('get_nameservers', $account, ['domain' => 'example.com'])->andReturn(new BrowserResult([
        'nameservers' => ['NS1.EXAMPLE.COM.', 'ns2.example.com'],
    ]));
    $runner->shouldReceive('run')->once()->with('set_nameservers', $account, [
        'domain' => 'example.com',
        'nameservers' => ['ns3.example.com', 'ns4.example.com'],
    ])->andReturn(new BrowserResult(['accepted' => true]));
    $registrar = new ZComRegistrar($account, $runner);

    $page = $registrar->listDomains();
    $nameservers = $registrar->getNameservers('Example.COM.');
    $change = $registrar->setNameservers('Example.COM.', ['NS3.EXAMPLE.COM.', 'ns4.example.com']);

    expect($page->domains[0]->name)->toBe('example.com')
        ->and($page->domains[0]->nameservers)->toBe(['ns1.example.com', 'ns2.example.com'])
        ->and($nameservers)->toBe(['ns1.example.com', 'ns2.example.com'])
        ->and($change->accepted)->toBeTrue()
        ->and($account->fresh()->credentials)->toBe([
            'password' => 'password',
            'storage_state' => ['cookies' => [['name' => 'session']]],
        ]);
});

test('registrar factory resolves the zcom adapter', function () {
    $account = RegistrarAccount::create([
        'provider' => RegistrarProvider::ZCom,
        'environment' => RegistrarEnvironment::Production,
        'label' => 'Z.com Factory',
        'username' => 'owner@example.com',
        'credentials' => ['password' => 'password'],
        'is_active' => true,
    ]);

    expect(app(RegistrarFactory::class)->for($account))->toBeInstanceOf(ZComRegistrar::class);
});
