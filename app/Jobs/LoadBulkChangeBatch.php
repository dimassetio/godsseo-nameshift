<?php

namespace App\Jobs;

use App\Enums\BulkItemStatus;
use App\Enums\ErrorCategory;
use App\Models\BulkChangeItem;
use App\Models\DomainMutationReservation;
use App\Services\BulkChangeStatusService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class LoadBulkChangeBatch implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /** @param list<int> $itemIds */
    public function __construct(public array $itemIds)
    {
        $this->onQueue('registrar-mutations');
    }

    public function handle(): void
    {
        $batch = $this->batch();
        if (! $batch || $batch->cancelled()) {
            return;
        }

        $batch->add(array_map(
            fn (int $itemId): ProcessBulkChangeItem => new ProcessBulkChangeItem($itemId),
            $this->itemIds,
        ));
    }

    public function failed(?Throwable $exception): void
    {
        $items = BulkChangeItem::query()
            ->with(['bulkChange', 'domain:id,name'])
            ->whereIn('id', $this->itemIds)
            ->whereIn('status', [BulkItemStatus::Pending, BulkItemStatus::Retrying])
            ->get();
        if ($items->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($items, $exception): void {
            foreach ($items as $item) {
                $cause = $exception?->getMessage() ?: 'The queue could not dispatch this domain update.';
                $item->update([
                    'status' => BulkItemStatus::Failed,
                    'error_category' => ErrorCategory::Unknown,
                    'error_message' => mb_substr("Domain {$item->domain->name}: {$cause}", 0, 500),
                    'completed_at' => now(),
                ]);
            }

            DomainMutationReservation::query()->whereIn('bulk_change_item_id', $items->pluck('id'))->delete();
            app(BulkChangeStatusService::class)->refresh($items->first()->bulkChange);
        });
    }
}
