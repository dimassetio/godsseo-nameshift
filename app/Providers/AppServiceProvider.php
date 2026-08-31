<?php

namespace App\Providers;

use App\Jobs\ProcessBulkChangeItem;
use App\Models\BulkChangeItem;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('registrar-api', function (ProcessBulkChangeItem $job) {
            $item = BulkChangeItem::with('domain.account')->find($job->itemId);
            if (! $item) {
                return Limit::none();
            }

            $key = strtolower($item->domain->account->provider->value).':'.$item->domain->registrar_account_id;

            return match ($item->domain->account->provider->value) {
                'NAMECOM' => [Limit::perSecond(15)->by($key), Limit::perHour(2800)->by($key)],
                'DYNADOT' => Limit::perSecond(1)->by($key),
                'SPACESHIP' => Limit::perMinutes(5, 5)->by($key),
                'ZCOM' => Limit::perMinute(10)->by($key),
                default => Limit::perSecond(5)->by($key),
            };
        });
    }
}
