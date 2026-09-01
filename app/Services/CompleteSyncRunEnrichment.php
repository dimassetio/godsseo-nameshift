<?php

namespace App\Services;

use App\Enums\RunStatus;
use App\Models\SyncRun;
use App\Models\SyncRunEnrichment;
use Illuminate\Support\Facades\DB;

class CompleteSyncRunEnrichment
{
    public function handle(int $syncRunId): void
    {
        DB::transaction(function () use ($syncRunId): void {
            $run = SyncRun::query()->lockForUpdate()->find($syncRunId);
            if (! $run || $run->status !== RunStatus::Running) {
                return;
            }

            $counts = $run->enrichments()
                ->selectRaw('status, count(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status');
            $total = (int) $counts->sum();
            $succeeded = (int) ($counts[SyncRunEnrichment::STATUS_SUCCEEDED] ?? 0);
            $failed = (int) ($counts[SyncRunEnrichment::STATUS_FAILED] ?? 0);
            $cancelled = (int) ($counts[SyncRunEnrichment::STATUS_CANCELLED] ?? 0);
            $completed = $succeeded + $failed + $cancelled;

            if ($total === 0 || $completed >= $total) {
                $lastFailure = $run->enrichments()
                    ->where('status', SyncRunEnrichment::STATUS_FAILED)
                    ->latest('completed_at')
                    ->first();
                $run->update([
                    'status' => RunStatus::Succeeded,
                    'failed_count' => $failed,
                    'progress_message' => $failed > 0
                        ? "Inventory saved. {$succeeded} of {$total} detail tasks succeeded; {$failed} failed."
                        : "Synchronization completed. All {$total} detail tasks succeeded.",
                    'error_message' => $lastFailure?->error_message,
                    'completed_at' => now(),
                ]);
                $run->account()->update(['last_synced_at' => now()]);

                return;
            }

            $run->update([
                'failed_count' => $failed,
                'progress_message' => "Inventory saved. Enriched {$completed} of {$total} detail tasks; {$failed} failed.",
            ]);
        });
    }
}
