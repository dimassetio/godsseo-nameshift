<?php

namespace App\Http\Controllers;

use App\Enums\BulkChangeStatus;
use App\Enums\BulkChangeType;
use App\Enums\BulkItemStatus;
use App\Enums\ErrorCategory;
use App\Enums\InventoryStatus;
use App\Enums\PreviewDisposition;
use App\Http\Requests\DomainFilterRequest;
use App\Jobs\LoadBulkChangeBatch;
use App\Models\BulkChange;
use App\Models\BulkChangeItem;
use App\Models\Domain;
use App\Models\DomainMutationReservation;
use App\Services\Audit;
use App\Services\BulkChangeStatusService;
use App\Services\BulkNameserverSpreadsheet;
use App\Services\NameserverSet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class BulkChangeController extends Controller
{
    public function template(DomainFilterRequest $request, BulkNameserverSpreadsheet $spreadsheet): HttpResponse
    {
        $records = Domain::query()
            ->matchingInventoryFilters($request->validated())
            ->orderBy('name')
            ->get(['name', 'nameservers'])
            ->map(fn (Domain $domain): array => [
                'domain' => $domain->name,
                'nameservers' => $domain->nameservers ?? [],
            ])
            ->all();

        return response($spreadsheet->template($records), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="nameshift-bulk-nameserver-template.xlsx"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function index(Request $request): Response
    {
        return Inertia::render('bulk-changes/index', [
            'bulkChanges' => BulkChange::with('user:id,name')->latest()->paginate(20),
        ]);
    }

    public function import(Request $request, BulkNameserverSpreadsheet $spreadsheet): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'extensions:xlsx', 'max:5120']]);
        $records = $spreadsheet->read($request->file('file'));
        $recordByDomain = collect($records)->keyBy('domain');
        $domains = Domain::with('account')->whereIn('name', $recordByDomain->keys())->get()->keyBy('name');
        $missing = $recordByDomain->keys()->diff($domains->keys())->values();
        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages([
                'file' => $missing
                    ->map(fn (string $domain): string => "Domain {$domain}: not found in the synchronized inventory.")
                    ->implode(' '),
            ]);
        }

        $orderedDomains = $recordByDomain->keys()->map(fn (string $name) => $domains->get($name));
        $targets = $orderedDomains->mapWithKeys(fn (Domain $domain) => [
            $domain->id => $recordByDomain->get($domain->name)['nameservers'],
        ])->all();
        $bulk = $this->createDraft($request, $orderedDomains, null, BulkChangeType::Import, null, $targets);

        return to_route('bulk-changes.show', $bulk)->with('success', 'Excel file validated. Review the changes before confirming.');
    }

    public function single(Request $request, Domain $domain): RedirectResponse
    {
        $data = $request->validate(['nameservers' => ['required', 'array', 'size:2'], 'nameservers.*' => ['required', 'string', 'max:253']]);
        $target = NameserverSet::normalize($data['nameservers']);
        $domain->load('account');
        if (! $domain->account->is_active || $domain->inventory_status !== InventoryStatus::Available) {
            throw ValidationException::withMessages(['nameservers' => 'This domain is not currently available for updates.']);
        }
        if (DomainMutationReservation::where('domain_id', $domain->id)->exists()) {
            throw ValidationException::withMessages(['nameservers' => 'This domain already has an active nameserver change.']);
        }
        if (NameserverSet::equal($domain->nameservers, $target)) {
            throw ValidationException::withMessages(['nameservers' => 'The nameservers have not changed.']);
        }

        $bulk = $this->createDraft($request, collect([$domain]), $target, BulkChangeType::Change);
        $this->confirmDraft($bulk);

        return back()->with('success', "Nameserver update for {$domain->name} queued.");
    }

    public function show(Request $request, BulkChange $bulkChange): Response
    {
        $status = $request->string('status')->toString();
        $items = $bulkChange->items()->with('domain.account:id,label,provider,is_active')
            ->when($status, fn ($q) => $q->where('status', $status))->orderBy('id')->paginate(50)->withQueryString();
        $changeable = $bulkChange->items()->whereNull('excluded_at')->where('preview_disposition', PreviewDisposition::Change)->count();
        $blocked = $bulkChange->items()->whereNull('excluded_at')->where('preview_disposition', PreviewDisposition::Blocked)->count();

        return Inertia::render('bulk-changes/show', [
            'bulkChange' => $bulkChange->load('user:id,name', 'parent:id,type,status'),
            'items' => $items,
            'previewCounts' => [
                'changeable' => $changeable,
                'skipped' => $bulkChange->items()->whereNull('excluded_at')->where('preview_disposition', PreviewDisposition::WillSkip)->count(),
                'blocked' => $blocked,
                'excluded' => $bulkChange->items()->whereNotNull('excluded_at')->count(),
            ],
            'isTerminal' => $bulkChange->status->isTerminal(),
            'statusFilter' => $status,
        ]);
    }

    public function exclude(BulkChange $bulkChange, BulkChangeItem $item): RedirectResponse
    {
        abort_unless($item->bulk_change_id === $bulkChange->id && $bulkChange->status === BulkChangeStatus::Draft, 409);
        abort_unless($item->preview_disposition === PreviewDisposition::Blocked, 422);
        $item->update(['excluded_at' => now()]);

        return back();
    }

    public function confirm(BulkChange $bulkChange): RedirectResponse
    {
        $confirmedBulk = $this->confirmDraft($bulkChange);

        return to_route('bulk-changes.show', $confirmedBulk)->with('success', 'Bulk change queued.');
    }

    private function confirmDraft(BulkChange $bulkChange): BulkChange
    {
        return DB::transaction(function () use ($bulkChange) {
            $bulk = BulkChange::lockForUpdate()->findOrFail($bulkChange->id);
            abort_unless($bulk->status === BulkChangeStatus::Draft, 409, 'This draft can no longer be confirmed.');
            $items = $bulk->items()->whereNull('excluded_at')->with('domain.account')->lockForUpdate()->get();
            if ($items->contains(fn ($item) => $item->preview_disposition === PreviewDisposition::Blocked)) {
                throw ValidationException::withMessages(['confirmation' => 'Exclude all blocked domains before confirmation.']);
            }
            $changeable = $items->where('preview_disposition', PreviewDisposition::Change)->count();
            if ($changeable === 0) {
                throw ValidationException::withMessages(['confirmation' => 'There are no nameserver changes to queue.']);
            }
            foreach ($items as $item) {
                if ($item->preview_disposition === PreviewDisposition::WillSkip) {
                    $item->update(['status' => BulkItemStatus::Skipped, 'completed_at' => now()]);

                    continue;
                }
                try {
                    DomainMutationReservation::create(['domain_id' => $item->domain_id, 'bulk_change_item_id' => $item->id]);
                } catch (Throwable) {
                    throw ValidationException::withMessages(['confirmation' => "{$item->domain->name} already has an active mutation."]);
                }
                $item->update(['status' => BulkItemStatus::Pending]);
            }
            $bulk->update(['status' => $changeable ? BulkChangeStatus::Queued : BulkChangeStatus::Succeeded, 'total_count' => $items->count(), 'pending_count' => $changeable, 'skipped_count' => $items->count() - $changeable, 'confirmed_at' => now(), 'completed_at' => $changeable ? null : now()]);
            Audit::record('bulk_change.confirmed', $bulk, ['changeable_count' => $changeable, 'total_count' => $items->count()]);

            $dispatchChunkSize = max(1, (int) config('nameshift.bulk_changes.dispatch_chunk_size'));
            $jobs = $items->where('preview_disposition', PreviewDisposition::Change)
                ->pluck('id')
                ->chunk($dispatchChunkSize)
                ->map(fn ($itemIds) => new LoadBulkChangeBatch($itemIds->values()->all()))
                ->all();
            if ($jobs) {
                $batch = Bus::batch($jobs)->name("Bulk change {$bulk->id}")->allowFailures()->dispatch();
                $bulk->update(['job_batch_id' => $batch->id]);
            }

            return $bulk;
        });
    }

    public function cancel(BulkChange $bulkChange, BulkChangeStatusService $statuses): RedirectResponse
    {
        abort_if($bulkChange->status === BulkChangeStatus::Draft || $bulkChange->status->isTerminal(), 409);
        DB::transaction(function () use ($bulkChange, $statuses) {
            $bulk = BulkChange::lockForUpdate()->findOrFail($bulkChange->id);
            $bulk->update(['cancel_requested_at' => now()]);
            $items = $bulk->items()->whereIn('status', [BulkItemStatus::Pending, BulkItemStatus::Retrying])->get();
            foreach ($items as $item) {
                $item->update(['status' => BulkItemStatus::Cancelled, 'completed_at' => now()]);
                DomainMutationReservation::where('bulk_change_item_id', $item->id)->delete();
            }
            $statuses->refresh($bulk);
            Audit::record('bulk_change.cancelled', $bulk, ['cancelled_count' => $items->count()]);
        });
        if ($bulkChange->job_batch_id && ($batch = Bus::findBatch($bulkChange->job_batch_id))) {
            $batch->cancel();
        }

        return back()->with('success', 'Pending work was cancelled.');
    }

    public function retry(Request $request, BulkChange $bulkChange): RedirectResponse
    {
        $items = $bulkChange->items()->with('domain.account')->where('status', BulkItemStatus::Failed)
            ->whereIn('error_category', collect(ErrorCategory::cases())->filter->retryable()->map->value)->get();
        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['retry' => 'There are no retryable failed items.']);
        }
        $bulk = $this->createDraft($request, $items->pluck('domain'), $bulkChange->target_nameservers, BulkChangeType::Retry, $bulkChange);
        Audit::record('bulk_change.retry_prepared', $bulk, ['parent_id' => $bulkChange->id]);

        return to_route('bulk-changes.show', $bulk);
    }

    public function rollback(Request $request, BulkChange $bulkChange): RedirectResponse
    {
        $data = $request->validate(['item_ids' => ['required', 'array', 'min:1', 'max:100'], 'item_ids.*' => ['integer', 'distinct']]);
        $items = $bulkChange->items()->with('domain.account')->whereIn('id', $data['item_ids'])->where('status', BulkItemStatus::Succeeded)->whereNotNull('old_nameservers')->get();
        if ($items->count() !== count($data['item_ids'])) {
            throw ValidationException::withMessages(['item_ids' => 'Rollback is available only for successful items with a snapshot.']);
        }
        $targets = $items->map(fn ($item) => json_encode(NameserverSet::normalize($item->old_nameservers, false)))->unique();
        if ($targets->count() !== 1) {
            throw ValidationException::withMessages(['item_ids' => 'Selected items do not share the same rollback target.']);
        }
        $target = json_decode($targets->first(), true);
        $bulk = $this->createDraft($request, $items->pluck('domain'), $target, BulkChangeType::Rollback, $bulkChange);
        Audit::record('bulk_change.rollback_prepared', $bulk, ['parent_id' => $bulkChange->id]);

        return to_route('bulk-changes.show', $bulk);
    }

    private function createDraft(Request $request, $domains, ?array $target, BulkChangeType $type, ?BulkChange $parent = null, array $targets = []): BulkChange
    {
        return DB::transaction(function () use ($request, $domains, $target, $type, $parent, $targets) {
            $normalizedTargets = $domains->mapWithKeys(function (Domain $domain) use ($target, $targets) {
                $domainTarget = $targets[$domain->id] ?? $target;
                if (! is_array($domainTarget)) {
                    throw ValidationException::withMessages(['nameservers' => "Missing nameservers for {$domain->name}."]);
                }

                return [$domain->id => NameserverSet::normalize($domainTarget)];
            });
            $uniqueTargets = $normalizedTargets->map(fn (array $value) => json_encode($value))->unique();
            $bulkTarget = $uniqueTargets->count() === 1 ? $normalizedTargets->first() : null;
            $bulk = BulkChange::create(['user_id' => $request->user()->id, 'parent_bulk_change_id' => $parent?->id, 'type' => $type, 'target_nameservers' => $bulkTarget, 'status' => BulkChangeStatus::Draft, 'total_count' => $domains->count()]);
            $reserved = DomainMutationReservation::whereIn('domain_id', $domains->pluck('id'))->pluck('domain_id')->all();
            foreach ($domains as $domain) {
                $domainTarget = $normalizedTargets->get($domain->id);
                $blocked = ! $domain->account->is_active || $domain->inventory_status !== InventoryStatus::Available || in_array($domain->id, $reserved, true);
                $disposition = $blocked ? PreviewDisposition::Blocked : (NameserverSet::equal($domain->nameservers, $domainTarget) ? PreviewDisposition::WillSkip : PreviewDisposition::Change);
                [$errorCategory, $errorMessage] = $this->previewError($domain, $reserved);
                $bulk->items()->create([
                    'domain_id' => $domain->id,
                    'preview_disposition' => $disposition,
                    'preview_nameservers' => $domain->nameservers,
                    'target_nameservers' => $domainTarget,
                    'error_category' => $errorCategory,
                    'error_message' => $errorMessage,
                ]);
            }
            Audit::record('bulk_change.preview_created', $bulk, ['type' => $type->value, 'domain_count' => $domains->count()]);

            return $bulk;
        });
    }

    /** @param list<int> $reserved */
    private function previewError(Domain $domain, array $reserved): array
    {
        if (! $domain->account->is_active) {
            return [ErrorCategory::Permission, "Domain {$domain->name}: registrar account {$domain->account->label} is inactive."];
        }

        if ($domain->inventory_status !== InventoryStatus::Available) {
            return [ErrorCategory::ActionRequired, "Domain {$domain->name}: inventory status is {$domain->inventory_status->value}."];
        }

        if (in_array($domain->id, $reserved, true)) {
            return [ErrorCategory::Conflict, "Domain {$domain->name}: another nameserver update is already active."];
        }

        return [null, null];
    }
}
