<?php

namespace App\Http\Controllers;

use App\Enums\BulkItemStatus;
use App\Models\BulkChange;
use App\Models\BulkChangeItem;
use App\Models\Domain;
use App\Models\RegistrarAccount;
use App\Models\SyncRun;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('dashboard', [
            'metrics' => [
                'domains' => Domain::count(),
                'accounts' => RegistrarAccount::where('is_active', true)->count(),
                'failedItems' => BulkChangeItem::where('status', BulkItemStatus::Failed)->count(),
            ],
            'domainsByProvider' => Domain::join('registrar_accounts', 'domains.registrar_account_id', '=', 'registrar_accounts.id')->selectRaw('registrar_accounts.provider, count(*) as aggregate')->groupBy('registrar_accounts.provider')->pluck('aggregate', 'provider'),
            'recentBulkChanges' => BulkChange::with('user:id,name')->latest()->limit(8)->get(),
            'recentSyncRuns' => SyncRun::with('account:id,label,provider')->latest()->limit(5)->get(),
        ]);
    }
}
