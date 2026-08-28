<?php

namespace App\Http\Controllers;

use App\Enums\RegistrarConnectionStatus;
use App\Enums\RunStatus;
use App\Http\Requests\SaveRegistrarAccountRequest;
use App\Jobs\SyncRegistrarAccount;
use App\Jobs\TestRegistrarConnection;
use App\Models\RegistrarAccount;
use App\Models\SyncRun;
use App\Services\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RegistrarAccountController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('settings/registrar-accounts', [
            'accounts' => $this->accounts(),
            'zcomEnabled' => (bool) config('services.zcom.enabled'),
        ]);
    }

    public function syncStatus(): JsonResponse
    {
        return response()->json(['accounts' => $this->accounts()]);
    }

    public function store(SaveRegistrarAccountRequest $request): RedirectResponse
    {
        $account = RegistrarAccount::create($request->accountData());
        Audit::record('registrar_account.created', $account, ['provider' => $account->provider->value, 'label' => $account->label]);

        return back()->with('success', 'Registrar account saved.');
    }

    public function update(SaveRegistrarAccountRequest $request, RegistrarAccount $registrarAccount): RedirectResponse
    {
        $registrarAccount->update($request->accountData());
        Audit::record('registrar_account.updated', $registrarAccount, ['provider' => $registrarAccount->provider->value, 'label' => $registrarAccount->label]);

        return back()->with('success', 'Registrar account updated.');
    }

    public function destroy(RegistrarAccount $registrarAccount): RedirectResponse
    {
        abort_if($registrarAccount->domains()->exists(), 422, 'Deactivate accounts that already have inventory instead of deleting them.');
        Audit::record('registrar_account.deleted', $registrarAccount, ['label' => $registrarAccount->label]);
        $registrarAccount->delete();

        return back()->with('success', 'Registrar account deleted.');
    }

    public function test(RegistrarAccount $registrarAccount): RedirectResponse
    {
        if (in_array($registrarAccount->last_test_status, [RegistrarConnectionStatus::Queued, RegistrarConnectionStatus::Running], true)) {
            return back()->withErrors(['connection' => 'A connection test is already active for this account.']);
        }

        $registrarAccount->update([
            'last_test_status' => RegistrarConnectionStatus::Queued,
            'last_test_message' => 'Connection test queued.',
            'last_tested_at' => null,
        ]);
        TestRegistrarConnection::dispatch($registrarAccount->id)->afterCommit();
        Audit::record('registrar_account.connection_test_requested', $registrarAccount);

        return back()->with('success', 'Connection test queued.');
    }

    public function sync(Request $request, RegistrarAccount $registrarAccount): RedirectResponse
    {
        abort_unless($registrarAccount->is_active, 422, 'Inactive accounts cannot be synchronized.');
        if ($registrarAccount->syncRuns()->whereIn('status', [RunStatus::Queued->value, RunStatus::Running->value])->exists()) {
            return back()->withErrors(['sync' => 'A synchronization is already active for this account.']);
        }
        $run = SyncRun::create(['registrar_account_id' => $registrarAccount->id, 'user_id' => $request->user()->id, 'status' => RunStatus::Queued]);
        SyncRegistrarAccount::dispatch($run->id)->afterCommit();
        Audit::record('registrar_account.sync_requested', $registrarAccount, ['sync_run_id' => $run->id]);

        return back()->with('success', 'Synchronization queued.');
    }

    public function syncAll(Request $request): RedirectResponse
    {
        $count = 0;
        RegistrarAccount::where('is_active', true)
            ->whereDoesntHave('syncRuns', fn ($query) => $query->whereIn('status', [RunStatus::Queued->value, RunStatus::Running->value]))
            ->each(function (RegistrarAccount $account) use ($request, &$count) {
                $run = SyncRun::create(['registrar_account_id' => $account->id, 'user_id' => $request->user()->id, 'status' => RunStatus::Queued]);
                SyncRegistrarAccount::dispatch($run->id)->afterCommit();
                $count++;
            });
        Audit::record('registrar_account.sync_all_requested', null, ['account_count' => $count]);

        return back()->with('success', "{$count} synchronization jobs queued.");
    }

    private function accounts()
    {
        $accounts = RegistrarAccount::withCount('domains')
            ->with(['syncRuns' => fn ($query) => $query->latest()->limit(1)])
            ->orderBy('label')
            ->get();

        $accounts->each(fn (RegistrarAccount $account) => $account->setAttribute('has_credentials', $account->credentials !== []));

        return $accounts;
    }
}
