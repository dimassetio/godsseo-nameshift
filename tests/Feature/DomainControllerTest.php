<?php

use App\Enums\InventoryStatus;
use App\Enums\RegistrarEnvironment;
use App\Enums\RegistrarProvider;
use App\Models\Domain;
use App\Models\RegistrarAccount;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected when viewing domains', function () {
    $this->get('/domains')->assertRedirect('/login');
});

test('filters domains by registrar status and exposes the available registrar statuses', function () {
    $account = RegistrarAccount::create([
        'provider' => RegistrarProvider::NameCom,
        'environment' => RegistrarEnvironment::Sandbox,
        'label' => 'Name.com',
        'username' => 'user-test',
        'credentials' => ['token' => 'token'],
        'is_active' => true,
    ]);
    Domain::create([
        'registrar_account_id' => $account->id,
        'name' => 'active.example',
        'nameservers' => [],
        'remote_status' => 'ACTIVE',
        'inventory_status' => InventoryStatus::Available,
    ]);
    Domain::create([
        'registrar_account_id' => $account->id,
        'name' => 'expired.example',
        'nameservers' => [],
        'remote_status' => 'EXPIRED',
        'inventory_status' => InventoryStatus::Available,
    ]);

    $response = $this->actingAs(User::factory()->create())->get('/domains?status=ACTIVE');

    $response->assertInertia(fn (Assert $page) => $page
        ->component('domains/index')
        ->has('domains.data', 1)
        ->where('domains.data.0.name', 'active.example')
        ->where('registrarStatuses', ['ACTIVE', 'EXPIRED']));
});

test('sorts domains in both directions by every sortable table column', function (string $sort) {
    $firstAccount = RegistrarAccount::create([
        'provider' => RegistrarProvider::NameCom,
        'environment' => RegistrarEnvironment::Sandbox,
        'label' => 'Alpha registrar',
        'username' => 'alpha-user',
        'credentials' => ['token' => 'token'],
        'is_active' => true,
    ]);
    $lastAccount = RegistrarAccount::create([
        'provider' => RegistrarProvider::Namecheap,
        'environment' => RegistrarEnvironment::Sandbox,
        'label' => 'Zulu registrar',
        'username' => 'zulu-user',
        'credentials' => ['api_key' => 'key'],
        'is_active' => true,
    ]);
    Domain::create([
        'registrar_account_id' => $firstAccount->id,
        'name' => 'alpha.example',
        'tld' => 'com',
        'renewal_price' => 10,
        'registered_at' => '2024-01-01',
        'expires_at' => '2027-01-01',
        'remote_status' => 'ACTIVE',
        'is_locked' => false,
        'privacy_enabled' => false,
        'auto_renew' => false,
        'nameservers' => ['a1.example.com', 'a2.example.com'],
        'inventory_status' => InventoryStatus::Available,
    ]);
    Domain::create([
        'registrar_account_id' => $lastAccount->id,
        'name' => 'zulu.example',
        'tld' => 'net',
        'renewal_price' => 20,
        'registered_at' => '2025-01-01',
        'expires_at' => '2028-01-01',
        'remote_status' => 'EXPIRED',
        'is_locked' => true,
        'privacy_enabled' => true,
        'auto_renew' => true,
        'nameservers' => ['z1.example.com', 'z2.example.com'],
        'inventory_status' => InventoryStatus::Available,
    ]);
    $user = User::factory()->create();

    $this->actingAs($user)->get("/domains?sort={$sort}&direction=asc")
        ->assertInertia(fn (Assert $page) => $page->where('domains.data.0.name', 'alpha.example'));
    $this->actingAs($user)->get("/domains?sort={$sort}&direction=desc")
        ->assertInertia(fn (Assert $page) => $page->where('domains.data.0.name', 'zulu.example'));
})->with([
    'domain' => 'domain',
    'tld' => 'tld',
    'registrar' => 'registrar',
    'renewal price' => 'renewal_price',
    'registered date' => 'registered_at',
    'expiration date' => 'expires_at',
    'remaining days' => 'remaining_days',
    'registrar status' => 'status',
    'locked' => 'is_locked',
    'privacy' => 'privacy_enabled',
    'auto renew' => 'auto_renew',
    'first nameserver' => 'nameserver_1',
    'second nameserver' => 'nameserver_2',
]);

test('uses an allowed domain page size and rejects unsafe table parameters', function () {
    $account = RegistrarAccount::create([
        'provider' => RegistrarProvider::NameCom,
        'environment' => RegistrarEnvironment::Sandbox,
        'label' => 'Name.com',
        'username' => 'user-test',
        'credentials' => ['token' => 'token'],
        'is_active' => true,
    ]);
    foreach (range(1, 30) as $number) {
        Domain::create([
            'registrar_account_id' => $account->id,
            'name' => "domain-{$number}.example",
            'nameservers' => [],
            'inventory_status' => InventoryStatus::Available,
        ]);
    }
    $user = User::factory()->create();

    $this->actingAs($user)->get('/domains?per_page=50')
        ->assertInertia(fn (Assert $page) => $page
            ->has('domains.data', 30)
            ->where('domains.per_page', 50)
            ->where('filters.per_page', 50));
    $this->actingAs($user)->from('/domains')->get('/domains?sort=name%3Bdrop+table+domains&direction=sideways&per_page=10')
        ->assertRedirect('/domains')
        ->assertSessionHasErrors(['sort', 'direction', 'per_page']);
});
