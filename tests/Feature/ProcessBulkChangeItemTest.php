<?php

use App\Enums\BulkChangeStatus;
use App\Enums\BulkChangeType;
use App\Enums\BulkItemStatus;
use App\Enums\ErrorCategory;
use App\Enums\InventoryStatus;
use App\Enums\PreviewDisposition;
use App\Enums\RegistrarEnvironment;
use App\Enums\RegistrarProvider;
use App\Jobs\LoadBulkChangeBatch;
use App\Jobs\ProcessBulkChangeItem;
use App\Models\BulkChange;
use App\Models\Domain;
use App\Models\DomainMutationReservation;
use App\Models\RegistrarAccount;
use App\Models\User;
use App\Registrars\Contracts\Registrar;
use App\Registrars\DTO\ChangeResult;
use App\Registrars\DTO\ConnectionResult;
use App\Registrars\DTO\DomainPage;
use App\Registrars\RegistrarFactory;
use App\Services\BulkChangeStatusService;

function pendingMutation(): array
{
    $user = User::factory()->create();
    $account = RegistrarAccount::create(['provider' => RegistrarProvider::NameCom, 'environment' => RegistrarEnvironment::Sandbox, 'label' => fake()->unique()->company(), 'username' => 'user', 'credentials' => ['token' => 'token'], 'is_active' => true]);
    $domain = Domain::create(['registrar_account_id' => $account->id, 'name' => fake()->unique()->domainName(), 'nameservers' => ['old1.example.com', 'old2.example.com'], 'inventory_status' => InventoryStatus::Available]);
    $bulk = BulkChange::create(['user_id' => $user->id, 'type' => BulkChangeType::Change, 'target_nameservers' => ['new1.example.com', 'new2.example.com'], 'status' => BulkChangeStatus::Queued, 'total_count' => 1, 'pending_count' => 1, 'confirmed_at' => now()]);
    $item = $bulk->items()->create(['domain_id' => $domain->id, 'preview_disposition' => PreviewDisposition::Change, 'status' => BulkItemStatus::Pending, 'preview_nameservers' => $domain->nameservers, 'target_nameservers' => $bulk->target_nameservers]);
    DomainMutationReservation::create(['domain_id' => $domain->id, 'bulk_change_item_id' => $item->id]);

    return compact('domain', 'bulk', 'item');
}

function fakeRegistrar(array $remote): Registrar
{
    return new class($remote) implements Registrar
    {
        public function __construct(private array $remote) {}

        public function testConnection(): ConnectionResult
        {
            return new ConnectionResult(true, 'ok');
        }

        public function listDomains(int $page = 1): DomainPage
        {
            return new DomainPage([], null);
        }

        public function getNameservers(string $domain): array
        {
            return $this->remote;
        }

        public function setNameservers(string $domain, array $nameservers): ChangeResult
        {
            return new ChangeResult(true);
        }
    };
}

test('bulk loader hydrates its batch with one mutation job per item', function () {
    [$job, $batch] = (new LoadBulkChangeBatch([10, 20, 30]))->withFakeBatch();

    $job->handle();

    expect($batch->added)->toHaveCount(3)
        ->and($batch->added[0])->toBeInstanceOf(ProcessBulkChangeItem::class)
        ->and($batch->added[0]->itemId)->toBe(10)
        ->and($batch->added[2]->itemId)->toBe(30);
});

test('mutation job reads before write and records a successful rollback snapshot', function () {
    ['domain' => $domain, 'bulk' => $bulk, 'item' => $item] = pendingMutation();
    $factory = Mockery::mock(RegistrarFactory::class);
    $factory->shouldReceive('for')->once()->andReturn(fakeRegistrar($domain->nameservers));
    (new ProcessBulkChangeItem($item->id))->handle($factory, new BulkChangeStatusService);
    expect($item->fresh()->status)->toBe(BulkItemStatus::Succeeded)
        ->and($item->fresh()->old_nameservers)->toBe(['old1.example.com', 'old2.example.com'])
        ->and($domain->fresh()->nameservers)->toBe(['new1.example.com', 'new2.example.com'])
        ->and($bulk->fresh()->status)->toBe(BulkChangeStatus::Succeeded)
        ->and(DomainMutationReservation::count())->toBe(0);
});

test('mutation job refuses remote drift as a conflict', function () {
    ['domain' => $domain, 'bulk' => $bulk, 'item' => $item] = pendingMutation();
    $factory = Mockery::mock(RegistrarFactory::class);
    $factory->shouldReceive('for')->once()->andReturn(fakeRegistrar(['external1.example.com', 'external2.example.com']));
    (new ProcessBulkChangeItem($item->id))->handle($factory, new BulkChangeStatusService);
    expect($item->fresh()->status)->toBe(BulkItemStatus::Conflict)
        ->and($item->fresh()->error_message)->toBe('Remote nameservers changed after preview.')
        ->and($domain->fresh()->nameservers)->toBe(['external1.example.com', 'external2.example.com'])
        ->and($bulk->fresh()->status)->toBe(BulkChangeStatus::Failed)
        ->and(DomainMutationReservation::count())->toBe(0);
});

test('an exhausted queue job records a visible terminal error', function () {
    ['bulk' => $bulk, 'item' => $item] = pendingMutation();

    (new ProcessBulkChangeItem($item->id))->failed(new RuntimeException('Worker terminated unexpectedly.'));

    expect($item->fresh()->status)->toBe(BulkItemStatus::Failed)
        ->and($item->fresh()->error_category)->toBe(ErrorCategory::Unknown)
        ->and($item->fresh()->error_message)->toBe('Worker terminated unexpectedly.')
        ->and($bulk->fresh()->status)->toBe(BulkChangeStatus::Failed)
        ->and(DomainMutationReservation::count())->toBe(0);
});

test('an unexpected processing error identifies the domain and concrete cause', function () {
    ['domain' => $domain, 'item' => $item] = pendingMutation();
    $factory = Mockery::mock(RegistrarFactory::class);
    $factory->shouldReceive('for')->once()->andThrow(new RuntimeException('Browser session could not start.'));

    (new ProcessBulkChangeItem($item->id))->handle($factory, new BulkChangeStatusService);

    expect($item->fresh()->status)->toBe(BulkItemStatus::Failed)
        ->and($item->fresh()->error_category)->toBe(ErrorCategory::Unknown)
        ->and($item->fresh()->error_message)->toBe("Domain {$domain->name}: Browser session could not start.")
        ->and(DomainMutationReservation::count())->toBe(0);
});

test('an exhausted bulk loader fails its pending domains with the dispatch cause', function () {
    ['domain' => $domain, 'bulk' => $bulk, 'item' => $item] = pendingMutation();

    (new LoadBulkChangeBatch([$item->id]))->failed(new RuntimeException('Queue storage is unavailable.'));

    expect($item->fresh()->status)->toBe(BulkItemStatus::Failed)
        ->and($item->fresh()->error_category)->toBe(ErrorCategory::Unknown)
        ->and($item->fresh()->error_message)->toBe("Domain {$domain->name}: Queue storage is unavailable.")
        ->and($bulk->fresh()->status)->toBe(BulkChangeStatus::Failed)
        ->and(DomainMutationReservation::count())->toBe(0);
});

test('a queued mutation exits when its registrar account was permanently deleted', function () {
    ['domain' => $domain, 'bulk' => $bulk, 'item' => $item] = pendingMutation();
    $account = $domain->account;
    $item->delete();
    $account->delete();
    $factory = Mockery::mock(RegistrarFactory::class);
    $factory->shouldNotReceive('for');

    (new ProcessBulkChangeItem($item->id))->handle($factory, new BulkChangeStatusService);

    $this->assertModelMissing($account);
    $this->assertModelMissing($domain);
    $this->assertModelMissing($item);
    $this->assertModelExists($bulk);
    expect(DomainMutationReservation::count())->toBe(0);
});
