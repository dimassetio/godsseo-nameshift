<?php

use App\Enums\ErrorCategory;
use App\Enums\RegistrarEnvironment;
use App\Enums\RegistrarProvider;
use App\Enums\RunStatus;
use App\Jobs\EnrichDomainNameservers;
use App\Jobs\EnrichRegistrarRenewalPrices;
use App\Jobs\SyncRegistrarAccount;
use App\Models\Domain;
use App\Models\RegistrarAccount;
use App\Models\SyncRun;
use App\Models\SyncRunEnrichment;
use App\Registrars\Contracts\ProvidesRenewalPrices;
use App\Registrars\Contracts\Registrar;
use App\Registrars\Contracts\RequiresNameserverEnrichment;
use App\Registrars\DTO\ChangeResult;
use App\Registrars\DTO\ConnectionResult;
use App\Registrars\DTO\DomainPage;
use App\Registrars\DTO\RemoteDomain;
use App\Registrars\Exceptions\ProviderException;
use App\Registrars\RegistrarFactory;
use App\Services\CompleteSyncRunEnrichment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

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

test('a stopped synchronization does not persist a provider page after cancellation', function () {
    $account = RegistrarAccount::create(['provider' => RegistrarProvider::NameCom, 'environment' => RegistrarEnvironment::Sandbox, 'label' => 'Stopped sync', 'username' => 'user', 'credentials' => ['token' => 'token'], 'is_active' => true]);
    $run = SyncRun::create(['registrar_account_id' => $account->id, 'status' => RunStatus::Queued]);
    $registrar = Mockery::mock(Registrar::class);
    $registrar->shouldReceive('listDomains')->once()->with(1)->andReturnUsing(function () use ($run): DomainPage {
        $run->update([
            'status' => RunStatus::Cancelled,
            'progress_message' => 'Synchronization stopped by user.',
            'completed_at' => now(),
        ]);

        return new DomainPage([
            new RemoteDomain('should-not-save.example', ['ns1.example.com', 'ns2.example.com']),
        ], null);
    });
    $factory = Mockery::mock(RegistrarFactory::class);
    $factory->shouldReceive('for')->once()->andReturn($registrar);

    (new SyncRegistrarAccount($run->id))->handle($factory);

    expect($run->fresh()->status)->toBe(RunStatus::Cancelled)
        ->and($run->fresh()->progress_message)->toBe('Synchronization stopped by user.')
        ->and($account->domains()->count())->toBe(0)
        ->and($account->fresh()->last_synced_at)->toBeNull();
});

test('a later inventory page failure keeps domains saved from earlier pages', function () {
    $account = RegistrarAccount::create(['provider' => RegistrarProvider::Porkbun, 'environment' => RegistrarEnvironment::Production, 'label' => 'Partial inventory', 'username' => 'user', 'credentials' => ['api_key' => 'key', 'secret_api_key' => 'secret'], 'is_active' => true]);
    $existing = Domain::create([
        'registrar_account_id' => $account->id,
        'name' => 'existing.com',
        'nameservers' => ['ns1.example.com', 'ns2.example.com'],
    ]);
    $run = SyncRun::create(['registrar_account_id' => $account->id, 'status' => RunStatus::Queued]);
    $registrar = Mockery::mock(Registrar::class);
    $registrar->shouldReceive('listDomains')->once()->with(1)->andReturn(new DomainPage([
        new RemoteDomain('saved-before-error.com', ['ns1.example.com', 'ns2.example.com']),
    ], 2));
    $registrar->shouldReceive('listDomains')->once()->with(2)->andThrow(
        new ProviderException(ErrorCategory::Permission, 'The second inventory page was rejected.'),
    );
    $factory = Mockery::mock(RegistrarFactory::class);
    $factory->shouldReceive('for')->once()->andReturn($registrar);

    (new SyncRegistrarAccount($run->id))->handle($factory);

    expect($run->fresh()->status)->toBe(RunStatus::Failed)
        ->and($account->domains()->where('name', 'saved-before-error.com')->exists())->toBeTrue()
        ->and($existing->fresh()->inventory_status->value)->toBe('AVAILABLE');
});

test('staged synchronization saves inventory before queueing per-domain details', function () {
    Queue::fake();
    $account = RegistrarAccount::create(['provider' => RegistrarProvider::Porkbun, 'environment' => RegistrarEnvironment::Production, 'label' => 'Staged Porkbun', 'username' => 'user', 'credentials' => ['api_key' => 'key', 'secret_api_key' => 'secret'], 'is_active' => true]);
    $domain = Domain::create([
        'registrar_account_id' => $account->id,
        'name' => 'example.com',
        'tld' => 'com',
        'nameservers' => ['old-ns1.example.com', 'old-ns2.example.com'],
        'renewal_price' => 9.50,
    ]);
    $run = SyncRun::create(['registrar_account_id' => $account->id, 'status' => RunStatus::Queued]);
    $registrar = new class implements ProvidesRenewalPrices, Registrar, RequiresNameserverEnrichment
    {
        public function testConnection(): ConnectionResult
        {
            return new ConnectionResult(true, 'Connection successful.');
        }

        public function listDomains(int $page = 1): DomainPage
        {
            return new DomainPage([
                new RemoteDomain('example.com', [], 'ACTIVE', 'com', null, null, '2027-01-01T00:00:00Z', true, false, true),
            ], null);
        }

        public function getNameservers(string $domain): array
        {
            return ['new-ns1.example.com', 'new-ns2.example.com'];
        }

        public function setNameservers(string $domain, array $nameservers): ChangeResult
        {
            return new ChangeResult(true);
        }

        public function renewalPrices(array $tlds): array
        {
            return ['com' => 12.50];
        }
    };
    $factory = Mockery::mock(RegistrarFactory::class);
    $factory->shouldReceive('for')->once()->andReturn($registrar);

    (new SyncRegistrarAccount($run->id))->handle($factory);

    expect($run->fresh()->status)->toBe(RunStatus::Running)
        ->and($run->fresh()->progress_message)->toBe('Inventory saved for 1 domains. Waiting for 2 detail tasks.')
        ->and($domain->fresh()->nameservers)->toBe(['old-ns1.example.com', 'old-ns2.example.com'])
        ->and($domain->fresh()->renewal_price)->toBe('9.50')
        ->and($domain->fresh()->is_locked)->toBeTrue()
        ->and($domain->fresh()->auto_renew)->toBeTrue()
        ->and($run->enrichments()->count())->toBe(2);
    Queue::assertPushed(EnrichDomainNameservers::class, 1);
    Queue::assertPushed(EnrichRegistrarRenewalPrices::class, 1);
});

test('failed nameserver enrichment preserves saved inventory and records the exact domain error', function () {
    $account = RegistrarAccount::create(['provider' => RegistrarProvider::Porkbun, 'environment' => RegistrarEnvironment::Production, 'label' => 'Partial Porkbun', 'username' => 'user', 'credentials' => ['api_key' => 'key', 'secret_api_key' => 'secret'], 'is_active' => true]);
    $domain = Domain::create([
        'registrar_account_id' => $account->id,
        'name' => 'fastcredit24.com',
        'tld' => 'com',
        'nameservers' => ['ns1.saved.example', 'ns2.saved.example'],
    ]);
    $run = SyncRun::create(['registrar_account_id' => $account->id, 'status' => RunStatus::Running, 'started_at' => now()]);
    $enrichment = SyncRunEnrichment::create([
        'sync_run_id' => $run->id,
        'domain_id' => $domain->id,
        'task_key' => 'nameservers:'.$domain->id,
        'type' => SyncRunEnrichment::TYPE_NAMESERVERS,
        'status' => SyncRunEnrichment::STATUS_QUEUED,
    ]);
    $registrar = Mockery::mock(Registrar::class);
    $registrar->shouldReceive('getNameservers')->once()->with('fastcredit24.com')->andThrow(
        new ProviderException(ErrorCategory::Permission, 'Porkbun rejected nameserver access for fastcredit24.com.', 'DOMAIN_NOT_ALLOWED'),
    );
    $factory = Mockery::mock(RegistrarFactory::class);
    $factory->shouldReceive('for')->once()->andReturn($registrar);

    (new EnrichDomainNameservers($enrichment->id))->handle($factory, app(CompleteSyncRunEnrichment::class));

    expect($domain->fresh()->nameservers)->toBe(['ns1.saved.example', 'ns2.saved.example'])
        ->and($enrichment->fresh()->status)->toBe(SyncRunEnrichment::STATUS_FAILED)
        ->and($enrichment->fresh()->error_category)->toBe(ErrorCategory::Permission)
        ->and($enrichment->fresh()->provider_error_code)->toBe('DOMAIN_NOT_ALLOWED')
        ->and($enrichment->fresh()->error_message)->toContain('fastcredit24.com')
        ->and($run->fresh()->status)->toBe(RunStatus::Succeeded)
        ->and($run->fresh()->failed_count)->toBe(1)
        ->and($run->fresh()->error_message)->toContain('fastcredit24.com');
});

test('namesilo synchronization enriches renewal prices once by tld after saving inventory', function () {
    Queue::fake();
    Http::preventStrayRequests();
    Http::fake([
        'sandbox.namesilo.com/apibatch/listDomains*' => Http::response(['reply' => [
            'code' => 300,
            'domains' => ['domain' => [['domain' => 'first-example.com'], ['domain' => 'second-example.com']]],
            'totalDomains' => 2,
        ]]),
        'sandbox.namesilo.com/apibatch/getDomainInfo*' => Http::response(['reply' => [
            'code' => 300,
            'status' => 'Active',
            'nameservers' => ['ns1.example.com', 'ns2.example.com'],
        ]]),
        'sandbox.namesilo.com/apibatch/getPrices*' => Http::response(['reply' => [
            'code' => 300,
            'detail' => 'success',
            'com' => ['registration' => 10.00, 'transfer' => 10.40, 'renew' => 11.95],
        ]]),
    ]);
    $account = RegistrarAccount::create(['provider' => RegistrarProvider::NameSilo, 'environment' => RegistrarEnvironment::Sandbox, 'label' => 'NameSilo staged prices', 'username' => 'user', 'credentials' => ['api_key' => 'key'], 'is_active' => true]);
    $run = SyncRun::create(['registrar_account_id' => $account->id, 'status' => RunStatus::Queued]);
    $factory = app(RegistrarFactory::class);

    (new SyncRegistrarAccount($run->id))->handle($factory);

    $enrichment = $run->enrichments()->where('type', SyncRunEnrichment::TYPE_RENEWAL_PRICES)->firstOrFail();
    expect($run->fresh()->status)->toBe(RunStatus::Running)
        ->and($account->domains()->pluck('renewal_price')->filter()->all())->toBe([]);
    Queue::assertPushed(EnrichRegistrarRenewalPrices::class, 1);

    (new EnrichRegistrarRenewalPrices($enrichment->id))->handle($factory, app(CompleteSyncRunEnrichment::class));

    expect($run->fresh()->status)->toBe(RunStatus::Succeeded)
        ->and($account->domains()->pluck('renewal_price')->all())->toBe(['11.95', '11.95']);
    Http::assertSentCount(4);
});

test('spaceship synchronization enriches renewal prices after saving its inventory', function () {
    Queue::fake();
    config()->set('services.spaceship.pricing_reader_url', 'https://pricing-reader.test/http://www.spaceship.com');
    Http::preventStrayRequests();
    Http::fake([
        'spaceship.dev/api/v1/domains?*' => Http::response(['total' => 1, 'items' => [[
            'name' => 'example.com',
            'lifecycleStatus' => 'active',
            'expirationDate' => '2027-01-01T00:00:00Z',
        ]]]),
        'pricing-reader.test/http://www.spaceship.com/domains/gtld/com/' => Http::response("Renew\n\n\$9.98/yr"),
    ]);
    $account = RegistrarAccount::create([
        'provider' => RegistrarProvider::Spaceship,
        'environment' => RegistrarEnvironment::Production,
        'label' => 'Spaceship staged prices',
        'username' => 'user',
        'credentials' => ['api_key' => 'key', 'api_secret' => 'secret'],
        'is_active' => true,
    ]);
    $run = SyncRun::create(['registrar_account_id' => $account->id, 'status' => RunStatus::Queued]);
    $factory = app(RegistrarFactory::class);

    (new SyncRegistrarAccount($run->id))->handle($factory);

    $enrichment = $run->enrichments()->where('type', SyncRunEnrichment::TYPE_RENEWAL_PRICES)->firstOrFail();
    expect($run->fresh()->status)->toBe(RunStatus::Running)
        ->and($account->domains()->firstOrFail()->renewal_price)->toBeNull();
    Queue::assertPushed(EnrichRegistrarRenewalPrices::class, 1);

    (new EnrichRegistrarRenewalPrices($enrichment->id))->handle($factory, app(CompleteSyncRunEnrichment::class));

    expect($account->domains()->firstOrFail()->renewal_price)->toBe('9.98')
        ->and($run->fresh()->status)->toBe(RunStatus::Succeeded);
});
