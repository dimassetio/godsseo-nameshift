<?php

use App\Enums\RegistrarEnvironment;
use App\Enums\RegistrarProvider;
use App\Enums\RunStatus;
use App\Jobs\SyncRegistrarAccount;
use App\Models\RegistrarAccount;
use App\Models\SyncRun;
use App\Registrars\Contracts\Registrar;
use App\Registrars\DTO\DomainPage;
use App\Registrars\DTO\RemoteDomain;
use App\Registrars\RegistrarFactory;

test('synchronization follows pagination and persists normalized inventory', function () {
    $this->travelTo('2026-12-25 12:00:00');
    $account = RegistrarAccount::create(['provider' => RegistrarProvider::NameCom, 'environment' => RegistrarEnvironment::Sandbox, 'label' => 'Sandbox', 'username' => 'user', 'credentials' => ['token' => 'token'], 'is_active' => true]);
    $run = SyncRun::create(['registrar_account_id' => $account->id, 'status' => RunStatus::Queued]);
    $registrar = Mockery::mock(Registrar::class);
    $registrar->shouldReceive('listDomains')->once()->with(1)->andReturn(new DomainPage([new RemoteDomain(
        name: 'one.example',
        nameservers: ['ns1.example.com', 'ns2.example.com'],
        status: 'ACTIVE',
        tld: 'example',
        renewalPrice: 10.5,
        registeredAt: '2024-01-01T00:00:00Z',
        expiresAt: '2027-01-01T00:00:00Z',
        isLocked: true,
        privacyEnabled: false,
        autoRenew: true,
    )], 2));
    $registrar->shouldReceive('listDomains')->once()->with(2)->andReturn(new DomainPage([new RemoteDomain('two.example', ['ns1.example.com', 'ns2.example.com'])], null));
    $factory = Mockery::mock(RegistrarFactory::class);
    $factory->shouldReceive('for')->with(Mockery::on(fn ($value) => $value->id === $account->id))->andReturn($registrar);
    (new SyncRegistrarAccount($run->id))->handle($factory);
    $domain = $account->domains()->where('name', 'one.example')->firstOrFail();

    expect($run->fresh()->status)->toBe(RunStatus::Succeeded)
        ->and($run->fresh()->created_count)->toBe(2)
        ->and($run->fresh()->progress_message)->toBe('Synchronization completed for 2 domains.')
        ->and($account->domains()->count())->toBe(2)
        ->and($domain->remote_status)->toBe('ACTIVE')
        ->and($domain->tld)->toBe('example')
        ->and($domain->renewal_price)->toBe('10.50')
        ->and($domain->registered_at->toDateString())->toBe('2024-01-01')
        ->and($domain->expires_at->toDateString())->toBe('2027-01-01')
        ->and($domain->remaining_days)->toBe(7)
        ->and($domain->is_locked)->toBeTrue()
        ->and($domain->privacy_enabled)->toBeFalse()
        ->and($domain->auto_renew)->toBeTrue();
});

test('a failed synchronization worker records a visible terminal error', function () {
    $account = RegistrarAccount::create(['provider' => RegistrarProvider::Porkbun, 'environment' => RegistrarEnvironment::Production, 'label' => 'Porkbun', 'username' => 'user', 'credentials' => ['api_key' => 'key', 'secret_api_key' => 'secret'], 'is_active' => true]);
    $run = SyncRun::create([
        'registrar_account_id' => $account->id,
        'status' => RunStatus::Running,
        'progress_message' => 'Fetching domain page 1 from Porkbun.',
        'started_at' => now(),
    ]);

    (new SyncRegistrarAccount($run->id))->failed(new RuntimeException('Worker terminated unexpectedly.'));

    expect($run->fresh()->status)->toBe(RunStatus::Failed)
        ->and($run->fresh()->progress_message)->toBe('Synchronization worker stopped unexpectedly.')
        ->and($run->fresh()->error_message)->toBe('Worker terminated unexpectedly.')
        ->and($run->fresh()->failed_count)->toBe(1)
        ->and($run->fresh()->completed_at)->not->toBeNull();
});

test('a queued synchronization exits when its registrar account was permanently deleted', function () {
    $account = RegistrarAccount::create(['provider' => RegistrarProvider::NameCom, 'environment' => RegistrarEnvironment::Sandbox, 'label' => 'Removed', 'username' => 'user', 'credentials' => ['token' => 'token'], 'is_active' => false]);
    $run = SyncRun::create(['registrar_account_id' => $account->id, 'status' => RunStatus::Queued]);
    $account->delete();
    $factory = Mockery::mock(RegistrarFactory::class);
    $factory->shouldNotReceive('for');

    (new SyncRegistrarAccount($run->id))->handle($factory);

    $this->assertModelMissing($account);
    $this->assertModelMissing($run);
});
