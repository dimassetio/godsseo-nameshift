<?php

namespace App\Models;

use App\Enums\InventoryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Domain extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'nameservers' => 'array',
            'inventory_status' => InventoryStatus::class,
            'last_seen_at' => 'datetime',
            'nameservers_observed_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
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
