<?php

use App\Enums\ErrorCategory;
use App\Enums\RegistrarConnectionStatus;
use App\Enums\RegistrarEnvironment;
use App\Enums\RegistrarProvider;
use App\Jobs\TestRegistrarConnection;
use App\Models\RegistrarAccount;
use App\Registrars\Contracts\Registrar;
use App\Registrars\DTO\ConnectionResult;
use App\Registrars\Exceptions\ProviderException;
use App\Registrars\RegistrarFactory;
use Illuminate\Queue\Middleware\WithoutOverlapping;

test('connection test records a successful result', function () {
    $account = RegistrarAccount::create([
        'provider' => RegistrarProvider::NameCom,
        'environment' => RegistrarEnvironment::Production,
        'label' => 'Name.com',
        'username' => 'operator',
        'credentials' => ['token' => 'secret'],
        'is_active' => true,
    ]);
    $registrar = Mockery::mock(Registrar::class);
    $registrar->shouldReceive('testConnection')->once()->andReturn(new ConnectionResult(true, 'Connected.'));
    $factory = Mockery::mock(RegistrarFactory::class);
    $factory->shouldReceive('for')->once()->with(Mockery::on(fn ($value) => $value->id === $account->id))->andReturn($registrar);

    (new TestRegistrarConnection($account->id))->handle($factory);

    $account->refresh();
    expect($account->last_test_status)->toBe(RegistrarConnectionStatus::Succeeded)
        ->and($account->last_test_message)->toBe('Connected.')
        ->and($account->last_tested_at)->not->toBeNull();
});

test('connection test records an authentication action requirement without retrying', function () {
    $account = RegistrarAccount::create([
        'provider' => RegistrarProvider::NameCom,
        'environment' => RegistrarEnvironment::Production,
        'label' => 'Name.com',
        'username' => 'operator',
        'credentials' => ['token' => 'secret'],
        'is_active' => true,
    ]);
    $registrar = Mockery::mock(Registrar::class);
    $registrar->shouldReceive('testConnection')->once()->andThrow(new ProviderException(ErrorCategory::ActionRequired, 'Enter the OTP.'));
    $factory = Mockery::mock(RegistrarFactory::class);
    $factory->shouldReceive('for')->once()->andReturn($registrar);

    (new TestRegistrarConnection($account->id))->handle($factory);

    $account->refresh();
    expect($account->last_test_status)->toBe(RegistrarConnectionStatus::ActionRequired)
        ->and($account->last_test_message)->toBe('Enter the OTP.');
});

test('connection tests share the registrar account lock across job classes', function () {
    $middleware = (new TestRegistrarConnection(17))->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and($middleware[0]->key)->toBe('registrar-account-17')
        ->and($middleware[0]->shareKey)->toBeTrue();
});
