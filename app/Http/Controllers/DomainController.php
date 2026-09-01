<?php

namespace App\Http\Controllers;

use App\Http\Requests\DomainFilterRequest;
use App\Models\Domain;
use App\Models\RegistrarAccount;
use App\Services\BulkNameserverSpreadsheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as HttpResponse;
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

    public function export(DomainFilterRequest $request, BulkNameserverSpreadsheet $spreadsheet): HttpResponse
    {
        $filters = array_merge([
            'sort' => 'domain',
            'direction' => 'asc',
        ], $request->validated());
        $records = Domain::query()
            ->with('account:id,label,provider')
            ->matchingInventoryFilters($filters)
            ->sorted($filters['sort'], $filters['direction'])
            ->get()
            ->map(fn (Domain $domain): array => [
                'domain' => $domain->name,
                'tld' => $domain->tld,
                'registrar' => $domain->account->label,
                'renewal_price' => $domain->renewal_price,
                'registered_at' => $domain->registered_at?->toDateString(),
                'expires_at' => $domain->expires_at?->toDateString(),
                'remaining_days' => $domain->remaining_days,
                'status' => $domain->remote_status,
                'is_locked' => $domain->is_locked,
                'privacy_enabled' => $domain->privacy_enabled,
                'auto_renew' => $domain->auto_renew,
                'nameserver_1' => $domain->nameservers[0] ?? null,
                'nameserver_2' => $domain->nameservers[1] ?? null,
            ])
            ->all();

        return response($spreadsheet->domainAssets($records), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="nameshift-domain-assets.xlsx"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
