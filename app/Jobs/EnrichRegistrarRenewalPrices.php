<?php

namespace App\Jobs;

use App\Enums\ErrorCategory;
use App\Enums\InventoryStatus;
use App\Enums\RunStatus;
use App\Models\SyncRunEnrichment;
use App\Registrars\Contracts\ProvidesRenewalPrices;
use App\Registrars\Exceptions\ProviderException;
use App\Registrars\RegistrarFactory;
use App\Services\CompleteSyncRunEnrichment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

class EnrichRegistrarRenewalPrices implements ShouldQueue
{
    use Queueable;

    public int $tries = 1000;

    public int $timeout = 120;

    public bool $failOnTimeout = true;

    public function __construct(public int $enrichmentId)
    {
        $this->onQueue('registrar-sync');
    }

    public function middleware(): array
    {
        $accountId = SyncRunEnrichment::query()
            ->whereKey($this->enrichmentId)
            ->join('sync_runs', 'sync_runs.id', '=', 'sync_run_enrichments.sync_run_id')
            ->value('sync_runs.registrar_account_id');

        return $accountId ? [
            (new WithoutOverlapping('registrar-account-'.$accountId))->shared()->releaseAfter(5)->expireAfter(150),
        ] : [];
    }

    public function backoff(): array
    {
        return [5];
    }

    public function handle(RegistrarFactory $factory, CompleteSyncRunEnrichment $completion): void
    {
        $enrichment = SyncRunEnrichment::with('syncRun.account')->find($this->enrichmentId);
        if (! $enrichment) {
            return;
        }
        if ($enrichment->syncRun->status !== RunStatus::Running) {
            $this->cancel($enrichment);

            return;
        }

        $claimed = $enrichment->newQuery()
            ->whereKey($enrichment->id)
            ->where('status', SyncRunEnrichment::STATUS_QUEUED)
            ->increment('attempt_count', 1, [
                'status' => SyncRunEnrichment::STATUS_RUNNING,
                'started_at' => $enrichment->started_at ?: now(),
            ]);
        if ($claimed === 0) {
            return;
        }
        $enrichment->refresh();

        try {
            $account = $enrichment->syncRun->account;
            $registrar = $factory->for($account);
            if (! $registrar instanceof ProvidesRenewalPrices) {
                throw new ProviderException(ErrorCategory::Validation, 'This registrar does not provide staged renewal pricing.');
            }

            $tlds = $account->domains()
                ->where('inventory_status', InventoryStatus::Available->value)
                ->whereNotNull('tld')
                ->distinct()
                ->pluck('tld')
                ->filter(fn (mixed $tld): bool => is_string($tld) && $tld !== '')
                ->values()
                ->all();
            $prices = $registrar->renewalPrices($tlds);
            if ($enrichment->syncRun->fresh()->status !== RunStatus::Running) {
                $this->cancel($enrichment);

                return;
            }

            foreach ($prices as $tld => $price) {
                $account->domains()
                    ->where('inventory_status', InventoryStatus::Available->value)
                    ->where('tld', ltrim(strtolower($tld), '.'))
                    ->update(['renewal_price' => $price, 'last_synced_at' => now()]);
            }

            $enrichment->update([
                'status' => SyncRunEnrichment::STATUS_SUCCEEDED,
                'error_category' => null,
                'provider_error_code' => null,
                'error_message' => null,
                'completed_at' => now(),
            ]);
            $completion->handle($enrichment->sync_run_id);
        } catch (Throwable $exception) {
            if ($exception instanceof ProviderException && $exception->retryable() && $enrichment->attempt_count < 3) {
                $enrichment->update([
                    'status' => SyncRunEnrichment::STATUS_QUEUED,
                    'error_category' => $exception->category,
                    'provider_error_code' => $exception->providerCode,
                    'error_message' => mb_substr($exception->getMessage(), 0, 500),
                ]);
                Log::warning('Registrar renewal price enrichment will be retried.', $this->logContext($enrichment, $exception));
                $this->release($enrichment->attempt_count === 1 ? 30 : 120);

                return;
            }

            $this->fail($enrichment, $exception, $completion);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $enrichment = SyncRunEnrichment::find($this->enrichmentId);
        if (! $enrichment) {
            return;
        }

        $this->fail(
            $enrichment,
            $exception ?? new \RuntimeException('The renewal price enrichment worker stopped unexpectedly.'),
            app(CompleteSyncRunEnrichment::class),
        );
    }

    private function cancel(SyncRunEnrichment $enrichment): void
    {
        $enrichment->newQuery()
            ->whereKey($enrichment->id)
            ->whereIn('status', [SyncRunEnrichment::STATUS_QUEUED, SyncRunEnrichment::STATUS_RUNNING])
            ->update(['status' => SyncRunEnrichment::STATUS_CANCELLED, 'completed_at' => now()]);
    }

    private function fail(SyncRunEnrichment $enrichment, Throwable $exception, CompleteSyncRunEnrichment $completion): void
    {
        $category = $exception instanceof ProviderException ? $exception->category : ErrorCategory::Unknown;
        $providerCode = $exception instanceof ProviderException ? $exception->providerCode : null;
        $updated = $enrichment->newQuery()
            ->whereKey($enrichment->id)
            ->whereIn('status', [SyncRunEnrichment::STATUS_QUEUED, SyncRunEnrichment::STATUS_RUNNING])
            ->update([
                'status' => SyncRunEnrichment::STATUS_FAILED,
                'error_category' => $category->value,
                'provider_error_code' => $providerCode,
                'error_message' => mb_substr($exception->getMessage(), 0, 500),
                'completed_at' => now(),
            ]);

        if ($updated > 0) {
            Log::error('Registrar renewal price enrichment failed.', $this->logContext($enrichment, $exception));
            $completion->handle($enrichment->sync_run_id);
        }
    }

    /** @return array<string, mixed> */
    private function logContext(SyncRunEnrichment $enrichment, Throwable $exception): array
    {
        return [
            'sync_run_id' => $enrichment->sync_run_id,
            'enrichment_id' => $enrichment->id,
            'attempt' => $enrichment->attempt_count,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ];
    }
}
