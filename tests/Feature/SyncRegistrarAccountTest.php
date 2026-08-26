<?php

use App\Enums\RegistrarEnvironment;
use App\Enums\RegistrarProvider;
use App\Enums\RunStatus;
use App\Jobs\SyncRegistrarAccount;
use App\Models\RegistrarAccount;
use App\Models\SyncRun;
use App\Registrars\Contracts\Registrar;
use App\Registrars\DTO\DomainPage;
use App\Registrars\DTO\RemoteDomain;
use App\Registrars\RegistrarFactory;

test('synchronization follows pagination and persists normalized inventory', function () {
    $account = RegistrarAccount::create(['provider' => RegistrarProvider::NameCom, 'environment' => RegistrarEnvironment::Sandbox, 'label' => 'Sandbox', 'username' => 'user', 'credentials' => ['token' => 'token'], 'is_active' => true]);
    $run = SyncRun::create(['registrar_account_id' => $account->id, 'status' => RunStatus::Queued]);
    $registrar = Mockery::mock(Registrar::class);
    $registrar->shouldReceive('listDomains')->once()->with(1)->andReturn(new DomainPage([new RemoteDomain('one.example', ['ns1.example.com', 'ns2.example.com'])], 2));
    $registrar->shouldReceive('listDomains')->once()->with(2)->andReturn(new DomainPage([new RemoteDomain('two.example', ['ns1.example.com', 'ns2.example.com'])], null));
    $factory = Mockery::mock(RegistrarFactory::class);
    $factory->shouldReceive('for')->with(Mockery::on(fn ($value) => $value->id === $account->id))->andReturn($registrar);
    (new SyncRegistrarAccount($run->id))->handle($factory);
    expect($run->fresh()->status)->toBe(RunStatus::Succeeded)->and($run->fresh()->created_count)->toBe(2)->and($account->domains()->count())->toBe(2);
});
