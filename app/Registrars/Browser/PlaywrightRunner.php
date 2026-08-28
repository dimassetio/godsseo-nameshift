<?php

namespace App\Registrars\Browser;

use App\Enums\ErrorCategory;
use App\Models\RegistrarAccount;
use App\Registrars\Exceptions\ProviderException;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use JsonException;

class PlaywrightRunner
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function run(string $operation, RegistrarAccount $account, array $payload = []): BrowserResult
    {
        if (! config('services.zcom.enabled')) {
            throw new ProviderException(ErrorCategory::ProviderPermanent, 'Z.com browser automation is disabled.');
        }

        $input = $this->encodeInput($operation, $account, $payload);
        $timeout = $operation === 'list_domains'
            ? (int) config('services.zcom.sync_timeout', 1800)
            : (int) config('services.zcom.timeout', 240);

        try {
            $process = Process::path(base_path())
                ->input($input)
                ->timeout($timeout);
            $browsersPath = config('services.zcom.browsers_path');
            if (is_string($browsersPath) && $browsersPath !== '') {
                $process->env(['PLAYWRIGHT_BROWSERS_PATH' => $browsersPath]);
            }

            $result = $process->run([
                (string) config('services.zcom.node_binary', 'node'),
                base_path('app/Registrars/Browser/zcom.mjs'),
            ]);
        } catch (ProcessTimedOutException) {
            throw new ProviderException(ErrorCategory::ProviderTemporary, 'Z.com browser automation timed out.');
        }

        if (! $result->successful()) {
            throw new ProviderException(ErrorCategory::ProviderTemporary, 'Z.com browser automation could not be started.');
        }

        try {
            $response = json_decode(trim($result->output()), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ProviderException(ErrorCategory::ProviderTemporary, 'Z.com browser automation returned an invalid response.');
        }

        if (! is_array($response) || ! isset($response['successful']) || ! is_bool($response['successful'])) {
            throw new ProviderException(ErrorCategory::ProviderTemporary, 'Z.com browser automation returned an incomplete response.');
        }

        if (! $response['successful']) {
            $error = is_array($response['error'] ?? null) ? $response['error'] : [];
            $category = ErrorCategory::tryFrom((string) ($error['category'] ?? '')) ?? ErrorCategory::Unknown;
            $message = is_string($error['message'] ?? null) ? $error['message'] : 'Z.com rejected the browser operation.';
            $providerCode = is_string($error['provider_code'] ?? null) ? $error['provider_code'] : null;

            throw new ProviderException($category, mb_substr(strip_tags($message), 0, 500), $providerCode);
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $storageState = is_array($response['storage_state'] ?? null) ? $response['storage_state'] : null;

        return new BrowserResult($data, $storageState);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encodeInput(string $operation, RegistrarAccount $account, array $payload): string
    {
        try {
            return json_encode([
                'operation' => $operation,
                'account' => [
                    'email' => $account->username,
                    'password' => (string) ($account->credentials['password'] ?? ''),
                    'storage_state' => $account->credentials['storage_state'] ?? null,
                ],
                'payload' => $payload,
                'config' => [
                    'login_url' => config('services.zcom.login_url'),
                    'domains_url' => config('services.zcom.domains_url'),
                    'headless' => (bool) config('services.zcom.headless', true),
                    'navigation_timeout_ms' => (int) config('services.zcom.navigation_timeout_ms', 45000),
                    'browser_executable_path' => config('services.zcom.browser_executable_path'),
                    'diagnostics_path' => config('services.zcom.diagnostics_path'),
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new ProviderException(ErrorCategory::Validation, 'Unable to encode the Z.com browser request.');
        }
    }
}
