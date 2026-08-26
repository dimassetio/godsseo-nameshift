<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\RegistrarAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DomainController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:253'], 'account' => ['nullable', 'integer'], 'status' => ['nullable', 'string']]);
        $domains = Domain::query()->with('account:id,label,provider,is_active', 'latestBulkItem.bulkChange:id,status')
            ->when($filters['search'] ?? null, fn ($q, $value) => $q->where('name', 'like', '%'.strtolower($value).'%'))
            ->when($filters['account'] ?? null, fn ($q, $value) => $q->where('registrar_account_id', $value))
            ->when($filters['status'] ?? null, fn ($q, $value) => $q->where('inventory_status', $value))
            ->orderBy('name')->paginate(25)->withQueryString();

        return Inertia::render('domains/index', [
            'domains' => $domains, 'filters' => $filters,
            'accounts' => RegistrarAccount::orderBy('label')->get(['id', 'label', 'provider', 'is_active']),
        ]);
    }

    public function mutationStatus(Domain $domain): JsonResponse
    {
        $item = $domain->latestBulkItem()->with('bulkChange:id,status')->first();

        return response()->json(['mutation' => $item]);
    }
}
