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

    public int $tries = 3;

    public int $timeout = 900;

    public function __construct(public int $syncRunId)
    {
        $this->onQueue('registrar-sync');
    }

    public function middleware(): array
    {
        $run = SyncRun::find($this->syncRunId);

        return $run ? [(new WithoutOverlapping('sync-account-'.$run->registrar_account_id))->expireAfter(1200)] : [];
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(RegistrarFactory $factory): void
    {
        $run = SyncRun::findOrFail($this->syncRunId);
        $account = RegistrarAccount::findOrFail($run->registrar_account_id);
        if (! $account->is_active) {
            $run->update(['status' => RunStatus::Failed, 'error_message' => 'The registrar account is inactive.', 'completed_at' => now()]);

            return;
        }

        $run->update(['status' => RunStatus::Running, 'started_at' => $run->started_at ?: now(), 'completed_at' => null, 'error_message' => null]);
        $started = microtime(true);
        $seen = [];
        $counts = ['created_count' => 0, 'updated_count' => 0, 'unchanged_count' => 0, 'failed_count' => 0];
        try {
            $registrar = $factory->for($account);
            $page = 1;
            do {
                $result = $registrar->listDomains($page);
                foreach ($result->domains as $remote) {
                    $domain = Domain::firstOrNew(['registrar_account_id' => $account->id, 'name' => $remote->name]);
                    $new = ! $domain->exists;
                    $domain->fill([
                        'nameservers' => $remote->nameservers,
                        'remote_status' => $remote->status,
                        'inventory_status' => InventoryStatus::Available,
                        'last_seen_at' => now(), 'last_synced_at' => now(), 'nameservers_observed_at' => now(),
                    ]);
                    $changed = $domain->isDirty(['nameservers', 'remote_status', 'inventory_status']);
                    $domain->save();
                    $seen[] = $domain->id;
                    $counts[$new ? 'created_count' : ($changed ? 'updated_count' : 'unchanged_count')]++;
                }
                $page = $result->nextPage;
            } while ($page !== null);

            $account->domains()->when($seen, fn ($query) => $query->whereNotIn('id', $seen))
                ->update(['inventory_status' => InventoryStatus::Unavailable->value]);
            $run->update(array_merge($counts, ['status' => RunStatus::Succeeded, 'completed_at' => now()]));
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
                'completed_at' => $willRetry ? null : now(),
            ]));
            Log::warning('Registrar synchronization failed.', ['sync_run_id' => $run->id, 'provider' => $account->provider->value, 'exception' => $exception::class]);
            if ($willRetry) {
                throw $exception;
            }
        }
    }
}
