<?php

namespace App\Services;

use App\Enums\BulkChangeStatus;
use App\Enums\BulkItemStatus;
use App\Models\BulkChange;

class BulkChangeStatusService
{
    public function refresh(BulkChange $bulkChange): void
    {
        $bulkChange = BulkChange::query()->lockForUpdate()->findOrFail($bulkChange->id);
        $counts = $bulkChange->items()->whereNull('excluded_at')->whereNotNull('status')
            ->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $get = fn (BulkItemStatus $status) => (int) ($counts[$status->value] ?? 0);
        $pending = $get(BulkItemStatus::Pending) + $get(BulkItemStatus::Retrying);
        $processing = $get(BulkItemStatus::Processing);
        $succeeded = $get(BulkItemStatus::Succeeded);
        $failed = $get(BulkItemStatus::Failed);
        $skipped = $get(BulkItemStatus::Skipped);
        $conflict = $get(BulkItemStatus::Conflict);
        $cancelled = $get(BulkItemStatus::Cancelled);
        $updates = compact('processing', 'succeeded', 'failed', 'skipped', 'conflict', 'cancelled');
        $updates = [
            'pending_count' => $pending, 'processing_count' => $processing, 'succeeded_count' => $succeeded,
            'failed_count' => $failed, 'skipped_count' => $skipped, 'conflict_count' => $conflict, 'cancelled_count' => $cancelled,
        ];
        if ($processing > 0 || ($bulkChange->started_at && $pending > 0)) {
            $updates['status'] = BulkChangeStatus::Running;
            $updates['started_at'] = $bulkChange->started_at ?: now();
        } elseif ($pending === 0) {
            $updates['completed_at'] = $bulkChange->completed_at ?: now();
            $updates['status'] = $bulkChange->cancel_requested_at
                ? BulkChangeStatus::Cancelled
                : (($failed + $conflict) === 0
                    ? BulkChangeStatus::Succeeded
                    : ($succeeded > 0 ? BulkChangeStatus::PartiallySucceeded : BulkChangeStatus::Failed));
        }
        $bulkChange->update($updates);
    }
}
