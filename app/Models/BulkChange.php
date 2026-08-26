<?php

namespace App\Models;

use App\Enums\BulkChangeStatus;
use App\Enums\BulkChangeType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BulkChange extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => BulkChangeType::class,
            'status' => BulkChangeStatus::class,
            'target_nameservers' => 'array',
            'confirmed_at' => 'datetime', 'cancel_requested_at' => 'datetime',
            'started_at' => 'datetime', 'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_bulk_change_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(BulkChangeItem::class);
    }
}
