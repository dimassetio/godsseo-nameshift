<?php

use App\Enums\BulkChangeStatus;
use App\Enums\BulkChangeType;
use App\Enums\BulkItemStatus;
use App\Enums\InventoryStatus;
use App\Enums\RegistrarEnvironment;
use App\Enums\RegistrarProvider;
use App\Jobs\ProcessBulkChangeItem;
use App\Models\BulkChange;
use App\Models\Domain;
use App\Models\DomainMutationReservation;
use App\Models\RegistrarAccount;
use App\Models\User;
use App\Services\BulkNameserverSpreadsheet;
use Illuminate\Bus\Batchable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Inertia\Testing\AssertableInertia as Assert;

function bulkDomain(array $overrides = []): Domain
{
    $account = RegistrarAccount::create([
        'provider' => RegistrarProvider::NameCom, 'environment' => RegistrarEnvironment::Sandbox,
        'label' => fake()->unique()->company(), 'username' => 'test-user', 'credentials' => ['token' => 'test-token'], 'is_active' => true,
    ]);

    return Domain::create(array_merge([
        'registrar_account_id' => $account->id, 'name' => fake()->unique()->domainName(),
        'nameservers' => ['old1.example.com', 'old2.example.com'], 'inventory_status' => InventoryStatus::Available,
    ], $overrides));
}

/** @param list<array{0: string, 1: string, 2: string}> $rows */
function nameserverWorkbook(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'nameshift-test-xlsx-');
    file_put_contents($path, app(BulkNameserverSpreadsheet::class)->template());
    $zip = new ZipArchive;
    $zip->open($path);
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    $xmlRows = '';
    foreach ($rows as $index => [$domain, $ns1, $ns2]) {
        $number = $index + 2;
        $cells = array_map(fn (string $column, string $value) => '<c r="'.$column.$number.'" t="inlineStr"><is><t>'.htmlspecialchars($value, ENT_XML1).'</t></is></c>', ['A', 'B', 'C'], [$domain, $ns1, $ns2]);
        $xmlRows .= '<row r="'.$number.'">'.implode('', $cells).'</row>';
    }
    $zip->addFromString('xl/worksheets/sheet1.xml', str_replace('</sheetData>', $xmlRows.'</sheetData>', $sheet));
    $zip->close();
    $contents = file_get_contents($path);
    unlink($path);

    return $contents;
}

test('bulk mutation jobs support Laravel batches', function () {
    expect(class_uses_recursive(ProcessBulkChangeItem::class))->toHaveKey(Batchable::class);
});

test('the Excel template contains current domains and nameservers matching the active filters', function () {
    $user = User::factory()->create();
    $matching = bulkDomain([
        'name' => 'matching-active.example.com',
        'nameservers' => ['current1.example.com', 'current2.example.com'],
        'remote_status' => 'ACTIVE',
    ]);
    Domain::create([
        'registrar_account_id' => $matching->registrar_account_id,
        'name' => 'matching-expired.example.com',
        'nameservers' => ['expired1.example.com', 'expired2.example.com'],
        'remote_status' => 'EXPIRED',
        'inventory_status' => InventoryStatus::Available,
    ]);
    Domain::create([
        'registrar_account_id' => $matching->registrar_account_id,
        'name' => 'ignored-active.example.com',
        'nameservers' => ['ignored1.example.com', 'ignored2.example.com'],
        'remote_status' => 'ACTIVE',
        'inventory_status' => InventoryStatus::Available,
    ]);
    bulkDomain([
        'name' => 'matching-other-account.example.com',
        'nameservers' => ['other1.example.com', 'other2.example.com'],
        'remote_status' => 'ACTIVE',
    ]);

    $response = $this->actingAs($user)->get('/bulk-changes/template?search=matching&account='.$matching->registrar_account_id.'&status=ACTIVE');

    $response
        ->assertOk()
        ->assertDownload('nameshift-bulk-nameserver-template.xlsx')
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $records = app(BulkNameserverSpreadsheet::class)->read(
        UploadedFile::fake()->createWithContent('bulk.xlsx', $response->getContent()),
    );
    expect($records)->toBe([[
        'domain' => 'matching-active.example.com',
        'nameservers' => ['current1.example.com', 'current2.example.com'],
    ]]);
});

test('the Excel template downloads every matching domain beyond the first hundred', function () {
    $user = User::factory()->create();
    $first = bulkDomain(['name' => 'template-001.example.com']);
    foreach (range(2, 105) as $number) {
        Domain::create([
            'registrar_account_id' => $first->registrar_account_id,
            'name' => sprintf('template-%03d.example.com', $number),
            'nameservers' => ['ns1.example.com', 'ns2.example.com'],
            'inventory_status' => InventoryStatus::Available,
        ]);
    }

    $response = $this->actingAs($user)->get('/bulk-changes/template?search=template-');

    $response->assertOk()->assertDownload('nameshift-bulk-nameserver-template.xlsx');
    $path = tempnam(sys_get_temp_dir(), 'bulk-template-test-');
    file_put_contents($path, $response->getContent());
    $zip = new ZipArchive;
    $zip->open($path);
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    unlink($path);

    expect(substr_count($sheet, '<row r="'))->toBe(106)
        ->and($sheet)->toContain('template-105.example.com')
        ->and($sheet)->toContain('dimension ref="A1:C106"');
});

test('Excel import includes only listed domains and supports per-domain targets', function () {
    $user = User::factory()->create();
    $first = bulkDomain(['name' => 'first.example.com']);
    $second = bulkDomain(['name' => 'second.example.com']);
    bulkDomain(['name' => 'not-listed.example.com']);
    $file = UploadedFile::fake()->createWithContent('bulk.xlsx', nameserverWorkbook([
        [$first->name, 'a1.example.com', 'a2.example.com'],
        [$second->name, 'b1.example.com', 'b2.example.com'],
    ]));

    $response = $this->actingAs($user)->post('/bulk-changes/import', ['file' => $file]);
    $bulk = BulkChange::firstOrFail();

    $response->assertRedirect("/bulk-changes/{$bulk->id}")->assertSessionHasNoErrors();
    expect($bulk->type)->toBe(BulkChangeType::Import)
        ->and($bulk->target_nameservers)->toBeNull()
        ->and($bulk->items()->count())->toBe(2)
        ->and($bulk->items()->whereHas('domain', fn ($query) => $query->where('name', 'not-listed.example.com'))->count())->toBe(0)
        ->and($bulk->items()->where('domain_id', $first->id)->value('target_nameservers'))->toBe(['a1.example.com', 'a2.example.com'])
        ->and($bulk->items()->where('domain_id', $second->id)->value('target_nameservers'))->toBe(['b1.example.com', 'b2.example.com']);
});

test('bulk confirmation needs only a button click', function () {
    Bus::fake();
    $user = User::factory()->create();
    $domain = bulkDomain(['name' => 'confirm.example.com']);
    $file = UploadedFile::fake()->createWithContent('bulk.xlsx', nameserverWorkbook([
        [$domain->name, 'ns1.example.com', 'ns2.example.com'],
    ]));
    $this->actingAs($user)->post('/bulk-changes/import', ['file' => $file]);
    $bulk = BulkChange::firstOrFail();

    $this->post("/bulk-changes/{$bulk->id}/confirm")->assertSessionHasNoErrors();

    expect($bulk->fresh()->status)->toBe(BulkChangeStatus::Queued)
        ->and($bulk->items()->firstOrFail()->status)->toBe(BulkItemStatus::Pending)
        ->and(DomainMutationReservation::count())->toBe(1);
    Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 1 && $batch->jobs->first() instanceof ProcessBulkChangeItem);
});

test('bulk detail exposes live processing counts for the progress display', function () {
    $user = User::factory()->create();
    $bulk = BulkChange::create([
        'user_id' => $user->id,
        'type' => BulkChangeType::Import,
        'status' => BulkChangeStatus::Running,
        'total_count' => 500,
        'pending_count' => 375,
        'processing_count' => 5,
        'succeeded_count' => 110,
        'failed_count' => 4,
        'skipped_count' => 3,
        'conflict_count' => 2,
        'cancelled_count' => 1,
        'started_at' => now(),
    ]);

    $response = $this->actingAs($user)->get("/bulk-changes/{$bulk->id}");

    $response->assertInertia(fn (Assert $page) => $page
        ->component('bulk-changes/show')
        ->where('bulkChange.total_count', 500)
        ->where('bulkChange.pending_count', 375)
        ->where('bulkChange.processing_count', 5)
        ->where('bulkChange.succeeded_count', 110)
        ->where('bulkChange.failed_count', 4)
        ->where('bulkChange.skipped_count', 3)
        ->where('bulkChange.conflict_count', 2)
        ->where('bulkChange.cancelled_count', 1)
        ->where('isTerminal', false));
});

test('single inline update creates and confirms one safe mutation', function () {
    Bus::fake();
    $user = User::factory()->create();
    $domain = bulkDomain(['name' => 'single.example.com']);

    $this->actingAs($user)->post("/domains/{$domain->id}/nameservers", [
        'nameservers' => ['NEW1.EXAMPLE.COM.', 'new2.example.com'],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $bulk = BulkChange::firstOrFail();
    expect($bulk->status)->toBe(BulkChangeStatus::Queued)
        ->and($bulk->items()->count())->toBe(1)
        ->and($bulk->items()->firstOrFail()->target_nameservers)->toBe(['new1.example.com', 'new2.example.com']);
    Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 1);
});

test('domain mutation status endpoint exposes terminal errors to the inline editor', function () {
    $user = User::factory()->create();
    $domain = bulkDomain(['name' => 'status.example.com']);
    $bulk = BulkChange::create([
        'user_id' => $user->id,
        'type' => BulkChangeType::Change,
        'target_nameservers' => ['new1.example.com', 'new2.example.com'],
        'status' => BulkChangeStatus::Failed,
        'total_count' => 1,
        'failed_count' => 1,
    ]);
    $bulk->items()->create([
        'domain_id' => $domain->id,
        'preview_disposition' => 'CHANGE',
        'status' => BulkItemStatus::Conflict,
        'preview_nameservers' => $domain->nameservers,
        'target_nameservers' => $bulk->target_nameservers,
        'error_category' => 'CONFLICT',
        'error_message' => 'Remote nameservers changed after preview.',
    ]);

    $this->actingAs($user)
        ->getJson("/domains/{$domain->id}/mutation-status")
        ->assertOk()
        ->assertJsonPath('mutation.status', 'CONFLICT')
        ->assertJsonPath('mutation.error_category', 'CONFLICT')
        ->assertJsonPath('mutation.error_message', 'Remote nameservers changed after preview.');
});

test('Excel import rejects domains outside synchronized inventory', function () {
    $user = User::factory()->create();
    $file = UploadedFile::fake()->createWithContent('bulk.xlsx', nameserverWorkbook([
        ['missing.example.com', 'ns1.example.com', 'ns2.example.com'],
    ]));

    $this->actingAs($user)->post('/bulk-changes/import', ['file' => $file])
        ->assertInvalid(['file' => 'Domain missing.example.com: not found in the synchronized inventory.']);
    expect(BulkChange::count())->toBe(0);
});

test('Excel import reports the row, domain, and cause for invalid row data', function () {
    $user = User::factory()->create();
    $file = UploadedFile::fake()->createWithContent('bulk.xlsx', nameserverWorkbook([
        ['broken.example.com', 'not a nameserver', 'ns2.example.com'],
    ]));

    $this->actingAs($user)->post('/bulk-changes/import', ['file' => $file])
        ->assertInvalid(['file' => 'Excel row 2 (broken.example.com):']);

    expect(BulkChange::count())->toBe(0);
});

test('bulk preview identifies each blocked domain and the cause', function () {
    $user = User::factory()->create();
    $domain = bulkDomain(['name' => 'inactive.example.com']);
    $domain->account->update(['is_active' => false]);
    $file = UploadedFile::fake()->createWithContent('bulk.xlsx', nameserverWorkbook([
        [$domain->name, 'ns1.example.com', 'ns2.example.com'],
    ]));

    $this->actingAs($user)->post('/bulk-changes/import', ['file' => $file])->assertSessionHasNoErrors();

    $item = BulkChange::firstOrFail()->items()->firstOrFail();
    expect($item->preview_disposition->value)->toBe('BLOCKED')
        ->and($item->error_category->value)->toBe('PERMISSION')
        ->and($item->error_message)->toBe("Domain {$domain->name}: registrar account {$domain->account->label} is inactive.");
});

test('a batch dispatch failure rolls confirmation state back', function () {
    $user = User::factory()->create();
    $domain = bulkDomain(['name' => 'rollback.example.com']);
    $file = UploadedFile::fake()->createWithContent('bulk.xlsx', nameserverWorkbook([
        [$domain->name, 'ns1.example.com', 'ns2.example.com'],
    ]));
    $this->actingAs($user)->post('/bulk-changes/import', ['file' => $file]);
    $bulk = BulkChange::firstOrFail();
    Bus::shouldReceive('batch')->once()->andThrow(new RuntimeException('Batch dispatch failed.'));
    $this->withoutExceptionHandling();

    expect(fn () => $this->post("/bulk-changes/{$bulk->id}/confirm"))->toThrow(RuntimeException::class, 'Batch dispatch failed.');

    expect($bulk->fresh()->status)->toBe(BulkChangeStatus::Draft)
        ->and($bulk->items()->firstOrFail()->status)->toBeNull()
        ->and(DomainMutationReservation::count())->toBe(0);
});
