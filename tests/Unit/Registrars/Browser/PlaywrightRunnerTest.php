<?php

use App\Enums\ErrorCategory;
use App\Enums\RegistrarEnvironment;
use App\Enums\RegistrarProvider;
use App\Models\RegistrarAccount;
use App\Registrars\Browser\PlaywrightRunner;
use App\Registrars\Exceptions\ProviderException;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

test('runner transmits credentials through standard input and parses the browser result', function () {
    config()->set('services.zcom.enabled', true);
    config()->set('services.zcom.browsers_path', '/opt/nameshift/playwright-browsers');
    $account = RegistrarAccount::make([
        'provider' => RegistrarProvider::ZCom,
        'environment' => RegistrarEnvironment::Production,
        'label' => 'Z.com',
        'username' => 'owner@example.com',
        'credentials' => ['password' => 'secret-password', 'storage_state' => ['cookies' => []]],
        'is_active' => true,
    ]);
    Process::fake([
        '*' => Process::result(output: json_encode([
            'successful' => true,
            'data' => ['nameservers' => ['ns1.example.com', 'ns2.example.com']],
            'storage_state' => ['cookies' => [['name' => 'session']]],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $result = (new PlaywrightRunner)->run('get_nameservers', $account, ['domain' => 'example.com']);

    expect($result->data['nameservers'])->toBe(['ns1.example.com', 'ns2.example.com'])
        ->and($result->storageState)->toBe(['cookies' => [['name' => 'session']]]);
    Process::assertRan(function (PendingProcess $process): bool {
        $input = json_decode((string) $process->input, true, flags: JSON_THROW_ON_ERROR);

        return $input['account']['password'] === 'secret-password'
            && $input['payload']['domain'] === 'example.com'
            && $process->environment['PLAYWRIGHT_BROWSERS_PATH'] === '/opt/nameshift/playwright-browsers'
            && ! str_contains(is_array($process->command) ? implode(' ', $process->command) : $process->command, 'secret-password');
    });
});

test('runner maps structured browser failures to provider exceptions', function () {
    config()->set('services.zcom.enabled', true);
    $account = RegistrarAccount::make([
        'provider' => RegistrarProvider::ZCom,
        'environment' => RegistrarEnvironment::Production,
        'label' => 'Z.com',
        'username' => 'owner@example.com',
        'credentials' => ['password' => 'secret-password'],
        'is_active' => true,
    ]);
    Process::fake([
        '*' => Process::result(output: '{"successful":false,"error":{"category":"ACTION_REQUIRED","message":"Enter the OTP."}}'),
    ]);

    expect(fn () => (new PlaywrightRunner)->run('test_connection', $account))
        ->toThrow(fn (ProviderException $exception) => $exception->category === ErrorCategory::ActionRequired && $exception->getMessage() === 'Enter the OTP.');
});

test('runner rejects malformed browser output', function () {
    config()->set('services.zcom.enabled', true);
    $account = RegistrarAccount::make([
        'provider' => RegistrarProvider::ZCom,
        'environment' => RegistrarEnvironment::Production,
        'label' => 'Z.com',
        'username' => 'owner@example.com',
        'credentials' => ['password' => 'secret-password'],
        'is_active' => true,
    ]);
    Process::fake(['*' => Process::result(output: 'not-json')]);

    expect(fn () => (new PlaywrightRunner)->run('test_connection', $account))
        ->toThrow(ProviderException::class, 'Z.com browser automation returned an invalid response.');
});
