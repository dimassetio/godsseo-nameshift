<?php

namespace App\Jobs;

use App\Enums\ErrorCategory;
use App\Enums\RegistrarConnectionStatus;
use App\Enums\RegistrarProvider;
use App\Models\RegistrarAccount;
use App\Registrars\Exceptions\ProviderException;
use App\Registrars\RegistrarFactory;
use App\Services\Audit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

class TestRegistrarConnection implements ShouldQueue
{
    use Queueable;

    public const TIMEOUT_SECONDS = 240;

    public const STALE_AFTER_SECONDS = self::TIMEOUT_SECONDS + 60;

    public int $tries = 3;

    public int $timeout = self::TIMEOUT_SECONDS;

    public bool $failOnTimeout = true;

    public function __construct(public int $registrarAccountId, ?RegistrarProvider $provider = null)
    {
        $this->onQueue($provider === RegistrarProvider::ZCom ? 'registrar-browser' : 'default');
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('registrar-account-'.$this->registrarAccountId))
                ->shared()
                ->expireAfter(300),
        ];
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(RegistrarFactory $factory): void
    {
        $account = RegistrarAccount::findOrFail($this->registrarAccountId);
        $account->update([
            'last_test_status' => RegistrarConnectionStatus::Running,
            'last_test_message' => 'Testing connection.',
            'last_tested_at' => null,
        ]);

        try {
            $result = $factory->for($account)->testConnection();
            $account->update([
                'last_test_status' => RegistrarConnectionStatus::Succeeded,
                'last_test_message' => $result->message,
                'last_tested_at' => now(),
            ]);
        } catch (ProviderException $exception) {
            if ($exception->retryable() && $this->attempts() < $this->tries) {
                $account->update([
                    'last_test_status' => RegistrarConnectionStatus::Queued,
                    'last_test_message' => $exception->getMessage(),
                ]);

                throw $exception;
            }

            $account->update([
                'last_test_status' => $exception->category === ErrorCategory::ActionRequired
                    ? RegistrarConnectionStatus::ActionRequired
                    : RegistrarConnectionStatus::Failed,
                'last_test_message' => mb_substr($exception->getMessage(), 0, 500),
                'last_tested_at' => now(),
            ]);
        } catch (Throwable $exception) {
            report($exception);
            $account->update([
                'last_test_status' => RegistrarConnectionStatus::Failed,
                'last_test_message' => 'Unexpected registrar connection error.',
                'last_tested_at' => now(),
            ]);
        }

        Log::info('Registrar connection test completed.', [
            'registrar_account_id' => $account->id,
            'provider' => $account->provider->value,
            'status' => $account->last_test_status->value,
        ]);
        Audit::record('registrar_account.connection_tested', $account, ['result' => $account->last_test_status->value]);
    }

    public function failed(?Throwable $exception): void
    {
        RegistrarAccount::whereKey($this->registrarAccountId)->update([
            'last_test_status' => RegistrarConnectionStatus::Failed->value,
            'last_test_message' => mb_substr($exception?->getMessage() ?: 'The connection test worker stopped unexpectedly.', 0, 500),
            'last_tested_at' => now(),
        ]);

        Log::error('Registrar connection test worker failed.', [
            'registrar_account_id' => $this->registrarAccountId,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
