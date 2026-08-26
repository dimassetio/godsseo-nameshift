<?php

namespace App\Http\Controllers;

use App\Enums\RegistrarEnvironment;
use App\Enums\RegistrarProvider;
use App\Enums\RunStatus;
use App\Jobs\SyncRegistrarAccount;
use App\Models\RegistrarAccount;
use App\Models\SyncRun;
use App\Registrars\RegistrarFactory;
use App\Services\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class RegistrarAccountController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('settings/registrar-accounts', [
            'accounts' => $this->accounts(true),
        ]);
    }

    public function syncStatus(): JsonResponse
    {
        return response()->json(['accounts' => $this->accounts()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, true);
        $account = RegistrarAccount::create($data);
        Audit::record('registrar_account.created', $account, ['provider' => $account->provider->value, 'label' => $account->label]);

        return back()->with('success', 'Registrar account saved.');
    }

    public function update(Request $request, RegistrarAccount $registrarAccount): RedirectResponse
    {
        $data = $this->validated($request, false);
        $registrarAccount->update($data);
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

    public function test(RegistrarAccount $registrarAccount, RegistrarFactory $factory): RedirectResponse
    {
        try {
            $result = $factory->for($registrarAccount)->testConnection();
            $registrarAccount->update(['last_test_status' => 'SUCCEEDED', 'last_test_message' => $result->message, 'last_tested_at' => now()]);
            Audit::record('registrar_account.connection_tested', $registrarAccount, ['result' => 'SUCCEEDED']);

            return back()->with('success', $result->message);
        } catch (Throwable $exception) {
            $message = mb_substr($exception->getMessage(), 0, 500);
            $registrarAccount->update(['last_test_status' => 'FAILED', 'last_test_message' => $message, 'last_tested_at' => now()]);
            Audit::record('registrar_account.connection_tested', $registrarAccount, ['result' => 'FAILED']);

            return back()->withErrors(['connection' => $message]);
        }
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

    private function accounts(bool $includeCredentials = false)
    {
        $accounts = RegistrarAccount::withCount('domains')
            ->with(['syncRuns' => fn ($query) => $query->latest()->limit(1)])
            ->orderBy('label')
            ->get();

        if ($includeCredentials) {
            $accounts->each(function (RegistrarAccount $account): void {
                $credentialKey = $account->provider === RegistrarProvider::Namecheap ? 'api_key' : 'token';
                $account->setAttribute('secret', $account->credentials[$credentialKey] ?? '');
            });
        }

        return $accounts;
    }

    private function validated(Request $request, bool $creating): array
    {
        $validated = $request->validate([
            'provider' => ['required', Rule::enum(RegistrarProvider::class)],
            'environment' => ['required', Rule::enum(RegistrarEnvironment::class)],
            'label' => ['required', 'string', 'max:255', Rule::unique('registrar_accounts')->ignore($request->route('registrarAccount'))],
            'username' => ['required', 'string', 'max:255'],
            'api_user' => ['nullable', 'string', 'max:255'],
            'client_ipv4' => ['nullable', 'ipv4'],
            'secret' => [$creating ? 'required' : 'nullable', 'string', 'max:2048'],
            'is_active' => ['required', 'boolean'],
        ]);
        if ($validated['provider'] === RegistrarProvider::Namecheap->value) {
            validator($validated, ['client_ipv4' => ['required', 'ipv4']])->validate();
            $credentials = ['api_key' => $validated['secret'] ?? ''];
        } else {
            $credentials = ['token' => $validated['secret'] ?? ''];
        }
        unset($validated['secret']);
        if ($credentials[array_key_first($credentials)] !== '') {
            $validated['credentials'] = $credentials;
        }

        return $validated;
    }
}
