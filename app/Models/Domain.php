<?php

namespace App\Models;

use App\Enums\InventoryStatus;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Domain extends Model
{
    protected $guarded = [];

    protected $appends = ['remaining_days'];

    protected function casts(): array
    {
        return [
            'nameservers' => 'array',
            'renewal_price' => 'decimal:2',
            'inventory_status' => InventoryStatus::class,
            'is_locked' => 'boolean',
            'privacy_enabled' => 'boolean',
            'auto_renew' => 'boolean',
            'registered_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'nameservers_observed_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    protected function remainingDays(): Attribute
    {
        return Attribute::get(fn (): ?int => $this->expires_at
            ? (int) now()->startOfDay()->diffInDays($this->expires_at->copy()->startOfDay(), false)
            : null);
    }

    /** @param array{search?: string|null, account?: int|null, status?: string|null} $filters */
    #[Scope]
    protected function matchingInventoryFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->where('name', 'like', '%'.Str::lower($search).'%'))
            ->when($filters['account'] ?? null, fn (Builder $query, int $accountId) => $query->where('registrar_account_id', $accountId))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('remote_status', $status));
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(RegistrarAccount::class, 'registrar_account_id');
    }

    public function bulkItems(): HasMany
    {
        return $this->hasMany(BulkChangeItem::class);
    }

    public function latestBulkItem(): HasOne
    {
        return $this->hasOne(BulkChangeItem::class)->latestOfMany();
    }
}
