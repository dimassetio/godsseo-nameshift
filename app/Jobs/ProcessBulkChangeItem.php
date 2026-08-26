<?php

namespace App\Jobs;

use App\Enums\BulkChangeStatus;
use App\Enums\BulkItemStatus;
use App\Enums\ErrorCategory;
use App\Models\BulkChangeItem;
use App\Models\DomainMutationReservation;
use App\Registrars\Exceptions\ProviderException;
use App\Registrars\RegistrarFactory;
use App\Services\BulkChangeStatusService;
use App\Services\NameserverSet;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessBulkChangeItem implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 50;

    public int $timeout = 60;

    public function __construct(public int $itemId)
    {
        $this->onQueue('registrar-mutations');
    }

    public function middleware(): array
    {
        $item = BulkChangeItem::with('domain')->find($this->itemId);
        if (! $item) {
            return [];
        }

        return [
            new RateLimited('registrar-api'),
            (new WithoutOverlapping('domain-mutation-'.$item->domain_id))->expireAfter(90),
            (new WithoutOverlapping('registrar-account-'.$item->domain->registrar_account_id))->releaseAfter(2)->expireAfter(90),
        ];
    }

    public function handle(RegistrarFactory $factory, BulkChangeStatusService $statuses): void
    {
        $item = BulkChangeItem::with(['bulkChange', 'domain.account'])->findOrFail($this->itemId);
        if (! $item->status || $item->status->isTerminal()) {
            return;
        }
        if ($item->bulkChange->cancel_requested_at || $item->bulkChange->status === BulkChangeStatus::Cancelled) {
            $this->terminal($item, BulkItemStatus::Cancelled, $statuses);

            return;
        }
        if ($item->attempt_count >= 5) {
            $this->terminal($item, BulkItemStatus::Failed, $statuses, ErrorCategory::ProviderTemporary, 'Maximum provider attempts exceeded.');

            return;
        }

        $item->update(['status' => BulkItemStatus::Processing, 'attempt_count' => $item->attempt_count + 1, 'started_at' => $item->started_at ?: now(), 'error_category' => null, 'error_message' => null]);
        DB::transaction(fn () => $statuses->refresh($item->bulkChange));
        try {
            $registrar = $factory->for($item->domain->account);
            $remote = $registrar->getNameservers($item->domain->name);
            if (NameserverSet::equal($remote, $item->target_nameservers)) {
                $item->domain->update(['nameservers' => $remote, 'nameservers_observed_at' => now()]);
                $this->terminal($item, BulkItemStatus::Skipped, $statuses, null, null, $remote);

                return;
            }
            if (! NameserverSet::equal($remote, $item->preview_nameservers)) {
                $item->domain->update(['nameservers' => $remote, 'nameservers_observed_at' => now()]);
                $this->terminal($item, BulkItemStatus::Conflict, $statuses, ErrorCategory::Conflict, 'Remote nameservers changed after preview.', $remote);

                return;
            }
            $result = $registrar->setNameservers($item->domain->name, $item->target_nameservers);
            if (! $result->accepted) {
                throw new ProviderException(ErrorCategory::ProviderPermanent, 'The registrar did not accept the change.', $result->providerCode);
            }
            DB::transaction(function () use ($item, $remote, $statuses) {
                $item->domain->update(['nameservers' => $item->target_nameservers, 'nameservers_observed_at' => now()]);
                $this->terminal($item, BulkItemStatus::Succeeded, $statuses, null, null, $remote, false);
            });
            Log::info('Nameserver mutation succeeded.', ['bulk_change_id' => $item->bulk_change_id, 'item_id' => $item->id, 'domain_id' => $item->domain_id, 'provider' => $item->domain->account->provider->value, 'attempt' => $item->attempt_count]);
        } catch (ProviderException $exception) {
            $item->refresh();
            if ($exception->retryable() && $item->attempt_count < 5) {
                $item->update(['status' => BulkItemStatus::Retrying, 'error_category' => $exception->category, 'provider_error_code' => $exception->providerCode, 'error_message' => $exception->getMessage()]);
                DB::transaction(fn () => $statuses->refresh($item->bulkChange));
                $delays = [30, 120, 300, 900];
                $this->release($exception->retryAfter ?: ($delays[max(0, $item->attempt_count - 1)] ?? 900));

                return;
            }
            $this->terminal($item, BulkItemStatus::Failed, $statuses, $exception->category, $exception->getMessage(), null, true, $exception->providerCode);
        } catch (Throwable $exception) {
            report($exception);
            $this->terminal($item, BulkItemStatus::Failed, $statuses, ErrorCategory::Unknown, 'Unexpected provider processing error.');
        }
    }

    public function failed(?Throwable $exception): void
    {
        $item = BulkChangeItem::with('bulkChange')->find($this->itemId);
        if (! $item || $item->status?->isTerminal()) {
            return;
        }

        DB::transaction(function () use ($item, $exception) {
            $item->update([
                'status' => BulkItemStatus::Failed,
                'error_category' => ErrorCategory::Unknown,
                'error_message' => mb_substr($exception?->getMessage() ?: 'The queue worker could not complete this nameserver update.', 0, 500),
                'completed_at' => now(),
            ]);
            DomainMutationReservation::where('bulk_change_item_id', $item->id)->delete();
            app(BulkChangeStatusService::class)->refresh($item->bulkChange);
        });
    }

    private function terminal(BulkChangeItem $item, BulkItemStatus $status, BulkChangeStatusService $statuses, ?ErrorCategory $category = null, ?string $message = null, ?array $old = null, bool $transaction = true, ?string $providerCode = null): void
    {
        $callback = function () use ($item, $status, $statuses, $category, $message, $old, $providerCode) {
            $item->update(['status' => $status, 'old_nameservers' => $old ?? $item->old_nameservers, 'error_category' => $category, 'provider_error_code' => $providerCode, 'error_message' => $message, 'completed_at' => now()]);
            DomainMutationReservation::where('bulk_change_item_id', $item->id)->delete();
            $statuses->refresh($item->bulkChange);
        };
        $transaction ? DB::transaction($callback) : $callback();
    }
}
