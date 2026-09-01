<?php

use App\Http\Controllers\AuditEventController;
use App\Http\Controllers\BulkChangeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\NameserverPresetController;
use App\Http\Controllers\RegistrarAccountController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('domains', [DomainController::class, 'index'])->name('domains.index');
    Route::get('domains/export', [DomainController::class, 'export'])->name('domains.export');
    Route::get('domains/{domain}/mutation-status', [DomainController::class, 'mutationStatus'])->name('domains.mutation-status');
    Route::post('domains/{domain}/nameservers', [BulkChangeController::class, 'single'])->name('domains.nameservers.update');

    Route::get('bulk-changes', [BulkChangeController::class, 'index'])->name('bulk-changes.index');
    Route::get('bulk-changes/template', [BulkChangeController::class, 'template'])->name('bulk-changes.template');
    Route::post('bulk-changes/import', [BulkChangeController::class, 'import'])->name('bulk-changes.import');
    Route::get('bulk-changes/{bulkChange}', [BulkChangeController::class, 'show'])->name('bulk-changes.show');
    Route::delete('bulk-changes/{bulkChange}/items/{item}', [BulkChangeController::class, 'exclude'])->name('bulk-changes.exclude');
    Route::post('bulk-changes/{bulkChange}/confirm', [BulkChangeController::class, 'confirm'])->name('bulk-changes.confirm');
    Route::post('bulk-changes/{bulkChange}/cancel', [BulkChangeController::class, 'cancel'])->name('bulk-changes.cancel');
    Route::post('bulk-changes/{bulkChange}/retry', [BulkChangeController::class, 'retry'])->name('bulk-changes.retry');
    Route::post('bulk-changes/{bulkChange}/rollback', [BulkChangeController::class, 'rollback'])->name('bulk-changes.rollback');

    Route::get('settings/registrar-accounts', [RegistrarAccountController::class, 'index'])->name('registrar-accounts.index');
    Route::get('settings/registrar-accounts/sync-status', [RegistrarAccountController::class, 'syncStatus'])->name('registrar-accounts.sync-status');
    Route::post('settings/registrar-accounts', [RegistrarAccountController::class, 'store'])->name('registrar-accounts.store');
    Route::put('settings/registrar-accounts/{registrarAccount}', [RegistrarAccountController::class, 'update'])->name('registrar-accounts.update');
    Route::delete('settings/registrar-accounts/{registrarAccount}', [RegistrarAccountController::class, 'destroy'])->name('registrar-accounts.destroy');
    Route::post('settings/registrar-accounts/{registrarAccount}/test', [RegistrarAccountController::class, 'test'])->name('registrar-accounts.test');
    Route::post('settings/registrar-accounts/{registrarAccount}/test/stop', [RegistrarAccountController::class, 'stopTest'])->name('registrar-accounts.test.stop');
    Route::post('settings/registrar-accounts/{registrarAccount}/sync', [RegistrarAccountController::class, 'sync'])->name('registrar-accounts.sync');
    Route::post('settings/registrar-accounts/{registrarAccount}/sync/stop', [RegistrarAccountController::class, 'stopSync'])->name('registrar-accounts.sync.stop');
    Route::post('settings/registrar-accounts/sync-all', [RegistrarAccountController::class, 'syncAll'])->name('registrar-accounts.sync-all');

    Route::get('settings/nameserver-presets', [NameserverPresetController::class, 'index'])->name('nameserver-presets.index');
    Route::post('settings/nameserver-presets', [NameserverPresetController::class, 'store'])->name('nameserver-presets.store');
    Route::put('settings/nameserver-presets/{nameserverPreset}', [NameserverPresetController::class, 'update'])->name('nameserver-presets.update');
    Route::delete('settings/nameserver-presets/{nameserverPreset}', [NameserverPresetController::class, 'destroy'])->name('nameserver-presets.destroy');
    Route::get('settings/audit-events', [AuditEventController::class, 'index'])->name('audit-events.index');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
