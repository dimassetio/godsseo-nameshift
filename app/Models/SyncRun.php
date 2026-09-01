<?php

namespace App\Models;

use App\Enums\RunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SyncRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => RunStatus::class, 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(RegistrarAccount::class, 'registrar_account_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function enrichments(): HasMany
    {
        return $this->hasMany(SyncRunEnrichment::class);
    }
}
