<?php

namespace App\Jobs;

use App\Enums\InventoryStatus;
use App\Enums\RunStatus;
use App\Models\Domain;
use App\Models\RegistrarAccount;
use App\Models\SyncRun;
use App\Registrars\Exceptions\ProviderException;
use App\Registrars\RegistrarFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncRegistrarAccount implements ShouldQueue
{
    use Queueable;

    public const TIMEOUT_SECONDS = 1800;

    public const STALE_AFTER_SECONDS = self::TIMEOUT_SECONDS + 300;

    public int $tries = 3;

    public int $timeout = self::TIMEOUT_SECONDS;

    public bool $failOnTimeout = true;

    public function __construct(public int $syncRunId)
    {
        $this->onQueue('registrar-sync');
    }

    public function middleware(): array
    {
        $run = SyncRun::find($this->syncRunId);

        return $run ? [
            (new WithoutOverlapping('registrar-account-'.$run->registrar_account_id))
                ->shared()
                ->expireAfter(2100),
        ] : [];
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(RegistrarFactory $factory): void
    {
        $run = SyncRun::find($this->syncRunId);
        if (! $run) {
            return;
        }

        $account = RegistrarAccount::findOrFail($run->registrar_account_id);
        if (! $account->is_active) {
            $run->update(['status' => RunStatus::Failed, 'error_message' => 'The registrar account is inactive.', 'completed_at' => now()]);

            return;
        }

        $run->update([
            'status' => RunStatus::Running,
            'started_at' => $run->started_at ?: now(),
            'completed_at' => null,
            'error_message' => null,
            'progress_message' => 'Starting registrar synchronization.',
        ]);
        $started = microtime(true);
        $seen = [];
        $counts = ['created_count' => 0, 'updated_count' => 0, 'unchanged_count' => 0, 'failed_count' => 0];
        try {
            $registrar = $factory->for($account);
            $page = 1;
            do {
                $run->update(array_merge($counts, [
                    'progress_message' => "Fetching domain page {$page} from {$account->label}.",
                ]));
                $result = $registrar->listDomains($page);
                foreach ($result->domains as $remote) {
                    $domain = Domain::firstOrNew(['registrar_account_id' => $account->id, 'name' => $remote->name]);
                    $new = ! $domain->exists;
                    $domain->fill([
                        'nameservers' => $remote->nameservers,
                        'remote_status' => $remote->status,
                        'tld' => $remote->tld,
                        'renewal_price' => $remote->renewalPrice,
                        'registered_at' => $remote->registeredAt,
                        'expires_at' => $remote->expiresAt,
                        'is_locked' => $remote->isLocked,
                        'privacy_enabled' => $remote->privacyEnabled,
                        'auto_renew' => $remote->autoRenew,
                        'inventory_status' => InventoryStatus::Available,
                        'last_seen_at' => now(), 'last_synced_at' => now(), 'nameservers_observed_at' => now(),
                    ]);
                    $changed = $domain->isDirty([
                        'nameservers',
                        'remote_status',
                        'tld',
                        'renewal_price',
                        'registered_at',
                        'expires_at',
                        'is_locked',
                        'privacy_enabled',
                        'auto_renew',
                        'inventory_status',
                    ]);
                    $domain->save();
                    $seen[] = $domain->id;
                    $counts[$new ? 'created_count' : ($changed ? 'updated_count' : 'unchanged_count')]++;
                }
                $page = $result->nextPage;
                $processedCount = $counts['created_count'] + $counts['updated_count'] + $counts['unchanged_count'];
                $run->update(array_merge($counts, [
                    'progress_message' => $page === null
                        ? "Processed {$processedCount} domains. Finalizing synchronization."
                        : "Processed {$processedCount} domains. Continuing with page {$page}.",
                ]));
            } while ($page !== null);

            $account->domains()->when($seen, fn ($query) => $query->whereNotIn('id', $seen))
                ->update(['inventory_status' => InventoryStatus::Unavailable->value]);
            $processedCount = $counts['created_count'] + $counts['updated_count'] + $counts['unchanged_count'];
            $run->update(array_merge($counts, [
                'status' => RunStatus::Succeeded,
                'progress_message' => "Synchronization completed for {$processedCount} domains.",
                'completed_at' => now(),
            ]));
            $account->update(['last_synced_at' => now()]);
            Log::info('Registrar synchronization completed.', ['sync_run_id' => $run->id, 'provider' => $account->provider->value, 'duration_ms' => (int) ((microtime(true) - $started) * 1000)] + $counts);
        } catch (Throwable $exception) {
            $willRetry = $exception instanceof ProviderException
                && $exception->retryable()
                && $this->attempts() < $this->tries;
            $run->update(array_merge($counts, [
                'status' => $willRetry ? RunStatus::Queued : RunStatus::Failed,
                'failed_count' => $counts['failed_count'] + 1,
                'error_message' => mb_substr($exception->getMessage(), 0, 500),
                'progress_message' => $willRetry
                    ? 'Temporary provider error. Waiting to retry synchronization.'
                    : 'Synchronization failed.',
                'completed_at' => $willRetry ? null : now(),
            ]));
            Log::warning('Registrar synchronization failed.', [
                'sync_run_id' => $run->id,
                'registrar_account_id' => $account->id,
                'provider' => $account->provider->value,
                'attempt' => $this->attempts(),
                'will_retry' => $willRetry,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
            if ($willRetry) {
                throw $exception;
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        SyncRun::query()
            ->whereKey($this->syncRunId)
            ->whereIn('status', [RunStatus::Queued->value, RunStatus::Running->value])
            ->update([
                'status' => RunStatus::Failed->value,
                'failed_count' => 1,
                'progress_message' => 'Synchronization worker stopped unexpectedly.',
                'error_message' => mb_substr(
                    $exception?->getMessage() ?: 'The synchronization worker stopped unexpectedly or exceeded its 30 minute timeout.',
                    0,
                    500,
                ),
                'completed_at' => now(),
            ]);

        Log::error('Registrar synchronization worker failed.', [
            'sync_run_id' => $this->syncRunId,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
