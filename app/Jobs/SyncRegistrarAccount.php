<?php

namespace App\Jobs;

use App\Enums\InventoryStatus;
use App\Enums\RunStatus;
use App\Models\Domain;
use App\Models\RegistrarAccount;
use App\Models\SyncRun;
use App\Models\SyncRunEnrichment;
use App\Registrars\Contracts\ProvidesRenewalPrices;
use App\Registrars\Contracts\RequiresNameserverEnrichment;
use App\Registrars\Exceptions\ProviderException;
use App\Registrars\RegistrarFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
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
            SyncRun::query()
                ->whereKey($run->id)
                ->where('status', RunStatus::Queued->value)
                ->update(['status' => RunStatus::Failed->value, 'error_message' => 'The registrar account is inactive.', 'completed_at' => now()]);

            return;
        }

        $startedRun = SyncRun::query()
            ->whereKey($run->id)
            ->where('status', RunStatus::Queued->value)
            ->update([
                'status' => RunStatus::Running->value,
                'started_at' => $run->started_at ?: now(),
                'completed_at' => null,
                'error_message' => null,
                'progress_message' => 'Starting registrar synchronization.',
            ]);

        if ($startedRun === 0) {
            return;
        }

        $run->refresh();
        $started = microtime(true);
        $seen = [];
        $counts = ['created_count' => 0, 'updated_count' => 0, 'unchanged_count' => 0, 'failed_count' => 0];
        try {
            $registrar = $factory->for($account);
            $stagesNameservers = $registrar instanceof RequiresNameserverEnrichment;
            $stagesRenewalPrices = $registrar instanceof ProvidesRenewalPrices;
            $page = 1;
            do {
                if ($run->refresh()->status === RunStatus::Cancelled) {
                    return;
                }

                $run->update(array_merge($counts, [
                    'progress_message' => "Fetching domain page {$page} from {$account->label}.",
                ]));
                $result = $registrar->listDomains($page);
                if ($run->refresh()->status === RunStatus::Cancelled) {
                    return;
                }

                foreach ($result->domains as $remote) {
                    if ($run->refresh()->status === RunStatus::Cancelled) {
                        return;
                    }

                    $domain = Domain::firstOrNew(['registrar_account_id' => $account->id, 'name' => $remote->name]);
                    $new = ! $domain->exists;
                    $attributes = [
                        'remote_status' => $remote->status,
                        'tld' => $remote->tld,
                        'registered_at' => $remote->registeredAt,
                        'expires_at' => $remote->expiresAt,
                        'is_locked' => $remote->isLocked,
                        'privacy_enabled' => $remote->privacyEnabled,
                        'auto_renew' => $remote->autoRenew,
                        'inventory_status' => InventoryStatus::Available,
                        'last_seen_at' => now(),
                        'last_synced_at' => now(),
                    ];
                    $trackedAttributes = [
                        'remote_status',
                        'tld',
                        'registered_at',
                        'expires_at',
                        'is_locked',
                        'privacy_enabled',
                        'auto_renew',
                        'inventory_status',
                    ];
                    if ($stagesNameservers) {
                        if ($new) {
                            $attributes['nameservers'] = [];
                        }
                    } else {
                        $attributes['nameservers'] = $remote->nameservers;
                        $attributes['nameservers_observed_at'] = now();
                        $trackedAttributes[] = 'nameservers';
                    }
                    if (! $stagesRenewalPrices) {
                        $attributes['renewal_price'] = $remote->renewalPrice;
                        $trackedAttributes[] = 'renewal_price';
                    }

                    $domain->fill($attributes);
                    $changed = $domain->isDirty($trackedAttributes);
                    $domain->save();
                    $seen[$domain->id] = true;
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

            $processedCount = $counts['created_count'] + $counts['updated_count'] + $counts['unchanged_count'];
            $enrichments = DB::transaction(function () use ($account, $counts, $processedCount, $run, $seen, $stagesNameservers, $stagesRenewalPrices): ?array {
                $lockedRun = SyncRun::query()->lockForUpdate()->findOrFail($run->id);
                if ($lockedRun->status !== RunStatus::Running) {
                    return null;
                }

                $seenDomainIds = array_keys($seen);
                $account->domains()->when($seenDomainIds, fn ($query) => $query->whereNotIn('id', $seenDomainIds))
                    ->update(['inventory_status' => InventoryStatus::Unavailable->value]);
                $timestamp = now();
                $rows = [];
                if ($stagesRenewalPrices && $seenDomainIds !== []) {
                    $rows[] = [
                        'sync_run_id' => $lockedRun->id,
                        'domain_id' => null,
                        'task_key' => 'renewal-prices',
                        'type' => SyncRunEnrichment::TYPE_RENEWAL_PRICES,
                        'status' => SyncRunEnrichment::STATUS_QUEUED,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ];
                }
                if ($stagesNameservers) {
                    foreach ($seenDomainIds as $domainId) {
                        $rows[] = [
                            'sync_run_id' => $lockedRun->id,
                            'domain_id' => $domainId,
                            'task_key' => 'nameservers:'.$domainId,
                            'type' => SyncRunEnrichment::TYPE_NAMESERVERS,
                            'status' => SyncRunEnrichment::STATUS_QUEUED,
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ];
                    }
                }
                if ($rows !== []) {
                    SyncRunEnrichment::query()->insert($rows);
                }

                $hasEnrichments = $rows !== [];
                $lockedRun->update(array_merge($counts, [
                    'status' => $hasEnrichments ? RunStatus::Running : RunStatus::Succeeded,
                    'progress_message' => $hasEnrichments
                        ? "Inventory saved for {$processedCount} domains. Waiting for ".count($rows).' detail tasks.'
                        : "Synchronization completed for {$processedCount} domains.",
                    'completed_at' => $hasEnrichments ? null : now(),
                ]));
                $account->update(['last_synced_at' => now()]);

                return $hasEnrichments
                    ? SyncRunEnrichment::query()
                        ->where('sync_run_id', $lockedRun->id)
                        ->get(['id', 'type'])
                        ->map(fn (SyncRunEnrichment $enrichment): array => ['id' => $enrichment->id, 'type' => $enrichment->type])
                        ->all()
                    : [];
            });

            if ($enrichments === null) {
                return;
            }

            foreach ($enrichments as $enrichment) {
                if ($enrichment['type'] === SyncRunEnrichment::TYPE_NAMESERVERS) {
                    EnrichDomainNameservers::dispatch($enrichment['id'])->afterCommit();
                } else {
                    EnrichRegistrarRenewalPrices::dispatch($enrichment['id'])->afterCommit();
                }
            }

            Log::info($enrichments === [] ? 'Registrar synchronization completed.' : 'Registrar inventory synchronization completed; enrichment queued.', [
                'sync_run_id' => $run->id,
                'provider' => $account->provider->value,
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
                'enrichment_count' => count($enrichments),
            ] + $counts);
        } catch (Throwable $exception) {
            if ($run->refresh()->status === RunStatus::Cancelled) {
                return;
            }

            $willRetry = $exception instanceof ProviderException
                && $exception->retryable()
                && $this->attempts() < $this->tries;
            $updated = SyncRun::query()
                ->whereKey($run->id)
                ->where('status', RunStatus::Running->value)
                ->update(array_merge($counts, [
                    'status' => $willRetry ? RunStatus::Queued : RunStatus::Failed,
                    'failed_count' => $counts['failed_count'] + 1,
                    'error_message' => mb_substr($exception->getMessage(), 0, 500),
                    'progress_message' => $willRetry
                        ? 'Temporary provider error. Waiting to retry synchronization.'
                        : 'Synchronization failed.',
                    'completed_at' => $willRetry ? null : now(),
                ]));

            if ($updated === 0) {
                return;
            }

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
