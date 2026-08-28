<?php

use App\Enums\RegistrarEnvironment;
use App\Enums\RegistrarProvider;
use App\Enums\RunStatus;
use App\Jobs\TestRegistrarConnection;
use App\Models\RegistrarAccount;
use App\Models\SyncRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

test('registrar credentials are encrypted and not serialized', function () {
    $user = User::factory()->create();
    $response = $this->actingAs($user)->post('/settings/registrar-accounts', [
        'provider' => 'NAMECOM', 'environment' => 'SANDBOX', 'label' => 'Name.com Sandbox',
        'username' => 'operator-test', 'secret' => 'top-secret-token', 'is_active' => true,
    ]);
    $response->assertRedirect()->assertSessionHasNoErrors();
    $account = RegistrarAccount::firstOrFail();
    expect($account->credentials)->toBe(['token' => 'top-secret-token']);
    expect(DB::table('registrar_accounts')->value('credentials'))->not->toContain('top-secret-token');
    expect($account->toArray())->not->toHaveKey('credentials');

    $this->actingAs($user)
        ->get('/settings/registrar-accounts')
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/registrar-accounts')
            ->where('accounts.0.has_credentials', true)
            ->missing('accounts.0.secret')
            ->missing('accounts.0.credentials'));

    $this->actingAs($user)
        ->getJson('/settings/registrar-accounts/sync-status')
        ->assertOk()
        ->assertJsonMissingPath('accounts.0.secret')
        ->assertJsonMissingPath('accounts.0.credentials');
});

test('zcom requires production and an email address when automation is enabled', function () {
    config()->set('services.zcom.enabled', true);
    $user = User::factory()->create();

    $this->actingAs($user)->post('/settings/registrar-accounts', [
        'provider' => 'ZCOM', 'environment' => 'SANDBOX', 'label' => 'Z.com',
        'username' => 'not-an-email', 'secret' => 'password', 'is_active' => true,
    ])->assertSessionHasErrors(['environment', 'username']);
});

test('zcom stores its password and invalidates its session when credentials change', function () {
    config()->set('services.zcom.enabled', true);
    $user = User::factory()->create();
    $account = RegistrarAccount::create([
        'provider' => RegistrarProvider::ZCom,
        'environment' => RegistrarEnvironment::Production,
        'label' => 'Z.com',
        'username' => 'old@example.com',
        'credentials' => ['password' => 'old-password', 'storage_state' => ['cookies' => [['name' => 'session']]]],
        'is_active' => true,
    ]);

    $this->actingAs($user)->put("/settings/registrar-accounts/{$account->id}", [
        'provider' => 'ZCOM', 'environment' => 'PRODUCTION', 'label' => 'Z.com',
        'username' => 'new@example.com', 'secret' => '', 'is_active' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($account->fresh()->credentials)->toBe(['password' => 'old-password']);

    $this->actingAs($user)->put("/settings/registrar-accounts/{$account->id}", [
        'provider' => 'ZCOM', 'environment' => 'PRODUCTION', 'label' => 'Z.com',
        'username' => 'new@example.com', 'secret' => 'new-password', 'is_active' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($account->fresh()->credentials)->toBe(['password' => 'new-password']);
});

test('an existing registrar provider cannot be changed', function () {
    $user = User::factory()->create();
    $account = RegistrarAccount::create([
        'provider' => RegistrarProvider::NameCom,
        'environment' => RegistrarEnvironment::Production,
        'label' => 'Registrar',
        'username' => 'operator',
        'credentials' => ['token' => 'secret'],
        'is_active' => true,
    ]);

    $this->actingAs($user)->put("/settings/registrar-accounts/{$account->id}", [
        'provider' => 'NAMECHEAP', 'environment' => 'PRODUCTION', 'label' => 'Registrar',
        'username' => 'operator', 'client_ipv4' => '192.0.2.10', 'secret' => '', 'is_active' => true,
    ])->assertSessionHasErrors('provider');

    expect($account->fresh()->provider)->toBe(RegistrarProvider::NameCom);
});

test('connection tests are queued and duplicate active tests are rejected', function () {
    $user = User::factory()->create();
    $account = RegistrarAccount::create([
        'provider' => RegistrarProvider::NameCom,
        'environment' => RegistrarEnvironment::Production,
        'label' => 'Name.com',
        'username' => 'operator',
        'credentials' => ['token' => 'secret'],
        'is_active' => true,
    ]);
    Queue::fake([TestRegistrarConnection::class]);

    $this->actingAs($user)
        ->post("/settings/registrar-accounts/{$account->id}/test")
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    Queue::assertPushed(TestRegistrarConnection::class, fn (TestRegistrarConnection $job) => $job->registrarAccountId === $account->id);
    expect($account->fresh()->last_test_status->value)->toBe('QUEUED');

    $this->actingAs($user)
        ->post("/settings/registrar-accounts/{$account->id}/test")
        ->assertSessionHasErrors('connection');
});

test('namecheap requires an ipv4 address', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->post('/settings/registrar-accounts', [
        'provider' => 'NAMECHEAP', 'environment' => 'SANDBOX', 'label' => 'NC',
        'username' => 'operator', 'secret' => 'secret', 'is_active' => true,
    ])->assertSessionHasErrors('client_ipv4');
});

test('namecom token can be updated while an empty token preserves the existing credential', function () {
    $user = User::factory()->create();
    $account = RegistrarAccount::create([
        'provider' => RegistrarProvider::NameCom,
        'environment' => RegistrarEnvironment::Production,
        'label' => 'Name.com Production',
        'username' => 'operator',
        'credentials' => ['token' => 'old-token'],
        'is_active' => true,
    ]);

    $accountData = [
        'provider' => 'NAMECOM',
        'environment' => 'PRODUCTION',
        'label' => 'Name.com Production',
        'username' => 'operator',
        'api_user' => '',
        'client_ipv4' => '',
        'is_active' => true,
    ];

    $this->actingAs($user)
        ->put("/settings/registrar-accounts/{$account->id}", [...$accountData, 'secret' => 'new-token'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($account->fresh()->credentials)->toBe(['token' => 'new-token']);

    $this->actingAs($user)
        ->put("/settings/registrar-accounts/{$account->id}", [...$accountData, 'secret' => ''])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($account->fresh()->credentials)->toBe(['token' => 'new-token']);
});

test('namecheap api key can be updated', function () {
    $user = User::factory()->create();
    $account = RegistrarAccount::create([
        'provider' => RegistrarProvider::Namecheap,
        'environment' => RegistrarEnvironment::Production,
        'label' => 'Namecheap Production',
        'username' => 'operator',
        'api_user' => 'api-operator',
        'client_ipv4' => '192.0.2.10',
        'credentials' => ['api_key' => 'old-api-key'],
        'is_active' => true,
    ]);

    $this->actingAs($user)->put("/settings/registrar-accounts/{$account->id}", [
        'provider' => 'NAMECHEAP',
        'environment' => 'PRODUCTION',
        'label' => 'Namecheap Production',
        'username' => 'operator',
        'api_user' => 'api-operator',
        'client_ipv4' => '192.0.2.10',
        'secret' => 'new-api-key',
        'is_active' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($account->fresh()->credentials)->toBe(['api_key' => 'new-api-key']);
});

test('an account cannot queue duplicate active synchronizations', function () {
    $user = User::factory()->create();
    $account = RegistrarAccount::create([
        'provider' => RegistrarProvider::Namecheap,
        'environment' => RegistrarEnvironment::Sandbox,
        'label' => 'Namecheap',
        'username' => 'operator',
        'client_ipv4' => '192.0.2.1',
        'credentials' => ['api_key' => 'secret'],
        'is_active' => true,
    ]);
    SyncRun::create(['registrar_account_id' => $account->id, 'user_id' => $user->id, 'status' => RunStatus::Queued]);

    $this->actingAs($user)
        ->post("/settings/registrar-accounts/{$account->id}/sync")
        ->assertRedirect()
        ->assertSessionHasErrors('sync');

    expect($account->syncRuns()->count())->toBe(1);
});

test('sync status endpoint returns the latest run without reloading the settings page', function () {
    $user = User::factory()->create();
    $account = RegistrarAccount::create([
        'provider' => RegistrarProvider::NameCom,
        'environment' => RegistrarEnvironment::Sandbox,
        'label' => 'Name.com',
        'username' => 'operator',
        'credentials' => ['token' => 'secret'],
        'is_active' => true,
    ]);
    SyncRun::create(['registrar_account_id' => $account->id, 'user_id' => $user->id, 'status' => RunStatus::Failed, 'error_message' => 'Provider unavailable.']);

    $this->actingAs($user)
        ->getJson('/settings/registrar-accounts/sync-status')
        ->assertOk()
        ->assertJsonPath('accounts.0.sync_runs.0.status', 'FAILED')
        ->assertJsonPath('accounts.0.sync_runs.0.error_message', 'Provider unavailable.');
});
