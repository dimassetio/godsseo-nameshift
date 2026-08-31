<?php

namespace App\Http\Controllers;

use App\Http\Requests\DomainFilterRequest;
use App\Models\Domain;
use App\Models\RegistrarAccount;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class DomainController extends Controller
{
    public function index(DomainFilterRequest $request): Response
    {
        $filters = array_merge([
            'sort' => 'domain',
            'direction' => 'asc',
            'per_page' => 25,
        ], $request->validated());
        $filters['per_page'] = (int) $filters['per_page'];
        $domains = Domain::query()->with('account:id,label,provider,is_active', 'latestBulkItem.bulkChange:id,status')
            ->matchingInventoryFilters($filters)
            ->sorted($filters['sort'], $filters['direction'])
            ->paginate($filters['per_page'])
            ->withQueryString();

        return Inertia::render('domains/index', [
            'domains' => $domains, 'filters' => $filters,
            'accounts' => RegistrarAccount::orderBy('label')->get(['id', 'label', 'provider', 'is_active']),
            'registrarStatuses' => Domain::query()->whereNotNull('remote_status')->distinct()->orderBy('remote_status')->pluck('remote_status'),
        ]);
    }

    public function mutationStatus(Domain $domain): JsonResponse
    {
        $item = $domain->latestBulkItem()->with('bulkChange:id,status')->first();

        return response()->json(['mutation' => $item]);
    }
}
