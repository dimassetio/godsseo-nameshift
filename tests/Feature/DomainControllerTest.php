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
