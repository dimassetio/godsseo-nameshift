<?php

use App\Enums\BulkChangeStatus;
use App\Enums\BulkChangeType;
use App\Enums\BulkItemStatus;
use App\Enums\PreviewDisposition;
use App\Enums\RegistrarConnectionStatus;
use App\Enums\RegistrarEnvironment;
use App\Enums\RegistrarProvider;
use App\Enums\RunStatus;
use App\Jobs\TestRegistrarConnection;
use App\Models\BulkChange;
use App\Models\Domain;
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

test('new zcom accounts are temporarily disabled while existing accounts remain editable', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/settings/registrar-accounts', [
        'provider' => 'ZCOM', 'environment' => 'PRODUCTION', 'label' => 'New Z.com',
        'username' => 'owner@example.com', 'secret' => 'password', 'is_active' => true,
    ])->assertSessionHasErrors('provider');

    $account = RegistrarAccount::create([
        'provider' => RegistrarProvider::ZCom,
        'environment' => RegistrarEnvironment::Production,
        'label' => 'Existing Z.com',
        'username' => 'owner@example.com',
        'credentials' => ['password' => 'password'],
        'is_active' => true,
    ]);

    $this->actingAs($user)->put("/settings/registrar-accounts/{$account->id}", [
        'provider' => 'ZCOM', 'environment' => 'PRODUCTION', 'label' => 'Updated Z.com',
        'username' => 'owner@example.com', 'secret' => '', 'is_active' => true,
    ])->assertSessionHasNoErrors();

    expect($account->fresh()->label)->toBe('Updated Z.com');
});

test('deleting a registrar account permanently deletes its domains and related history', function () {
    $user = User::factory()->create();
    $account = RegistrarAccount::create([
        'provider' => RegistrarProvider::NameCom,
        'environment' => RegistrarEnvironment::Production,
        'label' => 'Registrar to remove',
        'username' => 'operator',
        'credentials' => ['token' => 'secret'],
        'is_active' => true,
    ]);
    $domain = Domain::create([
        'registrar_account_id' => $account->id,
        'name' => 'example.com',
        'nameservers' => ['ns1.example.com', 'ns2.example.com'],
    ]);
    $bulkChange = BulkChange::create([
        'user_id' => $user->id,
        'type' => BulkChangeType::Change,
        'target_nameservers' => ['new1.example.com', 'new2.example.com'],
        'status' => BulkChangeStatus::Succeeded,
        'total_count' => 1,
        'succeeded_count' => 1,
    ]);
    $bulkItem = $bulkChange->items()->create([
        'domain_id' => $domain->id,
        'preview_disposition' => PreviewDisposition::Change,
        'status' => BulkItemStatus::Succeeded,
        'preview_nameservers' => $domain->nameservers,
        'old_nameservers' => $domain->nameservers,
        'target_nameservers' => ['new1.example.com', 'new2.example.com'],
    ]);

    $response = $this->actingAs($user)->delete("/settings/registrar-accounts/{$account->id}");

    $response->assertRedirect()->assertSessionHas('success', 'Registrar account and domains deleted.');
    $this->assertModelMissing($account);
    $this->assertModelMissing($domain);
    $this->assertModelMissing($bulkItem);
    $this->assertModelExists($bulkChange);
});

test('new registrar credentials are stored using each provider contract', function (
    string $provider,
    string $environment,
    array $input,
    array $expectedCredentials,
) {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/settings/registrar-accounts', [
        'provider' => $provider,
        'environment' => $environment,
        'label' => $provider,
        'username' => 'operator',
        'is_active' => true,
        ...$input,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(RegistrarAccount::where('label', $provider)->firstOrFail()->credentials)->toBe($expectedCredentials);
})->with([
    'NameSilo' => ['NAMESILO', 'SANDBOX', ['secret' => 'api-key'], ['api_key' => 'api-key']],
    'Dynadot' => ['DYNADOT', 'SANDBOX', ['secret' => 'api-key'], ['api_key' => 'api-key']],
    'Porkbun' => ['PORKBUN', 'SANDBOX', ['api_key' => 'public-key', 'secret' => 'secret-key'], ['api_key' => 'public-key', 'secret_api_key' => 'secret-key']],
    'Spaceship' => ['SPACESHIP', 'PRODUCTION', ['api_key' => 'public-key', 'secret' => 'secret-key'], ['api_key' => 'public-key', 'api_secret' => 'secret-key']],
    'Infomaniak' => ['INFOMANIAK', 'PRODUCTION', ['secret' => 'token'], ['token' => 'token']],
]);

test('paired api providers require both credentials and production-only providers reject sandbox', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/settings/registrar-accounts', [
        'provider' => 'PORKBUN', 'environment' => 'SANDBOX', 'label' => 'Porkbun',
        'username' => 'operator', 'secret' => 'secret-key', 'is_active' => true,
    ])->assertSessionHasErrors('api_key');

    foreach (['SPACESHIP', 'INFOMANIAK'] as $provider) {
        $this->actingAs($user)->post('/settings/registrar-accounts', [
            'provider' => $provider, 'environment' => 'SANDBOX', 'label' => $provider,
            'username' => 'operator', 'api_key' => 'public-key', 'secret' => 'secret-key', 'is_active' => true,
        ])->assertSessionHasErrors('environment');
    }
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

    Queue::assertPushed(TestRegistrarConnection::class, fn (TestRegistrarConnection $job) => $job->registrarAccountId === $account->id && $job->queue === 'default');
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

test('sync status marks a run as failed after the worker stops reporting progress', function () {
    $user = User::factory()->create();
    $account = RegistrarAccount::create([
        'provider' => RegistrarProvider::Porkbun,
        'environment' => RegistrarEnvironment::Production,
        'label' => 'Stalled Porkbun',
        'username' => 'operator',
        'credentials' => ['api_key' => 'key', 'secret_api_key' => 'secret'],
        'is_active' => true,
    ]);
    $run = SyncRun::create([
        'registrar_account_id' => $account->id,
        'user_id' => $user->id,
        'status' => RunStatus::Running,
        'progress_message' => 'Fetching domain page 1 from Stalled Porkbun.',
        'started_at' => now()->subMinutes(40),
    ]);
    $run->timestamps = false;
    $run->update(['updated_at' => now()->subMinutes(36)]);

    $this->actingAs($user)
        ->getJson('/settings/registrar-accounts/sync-status')
        ->assertOk()
        ->assertJsonPath('accounts.0.sync_runs.0.status', 'FAILED')
        ->assertJsonPath('accounts.0.sync_runs.0.progress_message', 'Synchronization stopped reporting progress.')
        ->assertJsonPath(
            'accounts.0.sync_runs.0.error_message',
            'No synchronization activity was recorded for more than 35 minutes. Check the registrar-sync queue worker and application logs, then retry.',
        );

    expect($run->fresh()->status)->toBe(RunStatus::Failed)
        ->and($run->fresh()->completed_at)->not->toBeNull();
});

test('sync status marks a connection test as failed after its worker stops reporting progress', function () {
    $user = User::factory()->create();
    $account = RegistrarAccount::create([
        'provider' => RegistrarProvider::NameSilo,
        'environment' => RegistrarEnvironment::Production,
        'label' => 'Stalled NameSilo',
        'username' => 'operator',
        'credentials' => ['api_key' => 'secret'],
        'is_active' => true,
        'last_test_status' => RegistrarConnectionStatus::Running,
        'last_test_message' => 'Testing connection.',
    ]);
    RegistrarAccount::query()->whereKey($account->id)->update(['updated_at' => now()->subMinutes(6)]);

    $this->actingAs($user)
        ->getJson('/settings/registrar-accounts/sync-status')
        ->assertOk()
        ->assertJsonPath('accounts.0.last_test_status', 'FAILED')
        ->assertJsonPath(
            'accounts.0.last_test_message',
            'The connection test stopped reporting progress for more than five minutes. Verify the queue worker and retry.',
        );

    expect($account->fresh()->last_test_status)->toBe(RegistrarConnectionStatus::Failed)
        ->and($account->fresh()->last_tested_at)->not->toBeNull();
});
