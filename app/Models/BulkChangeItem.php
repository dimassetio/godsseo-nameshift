<?php

namespace App\Models;

use App\Enums\BulkItemStatus;
use App\Enums\ErrorCategory;
use App\Enums\PreviewDisposition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BulkChangeItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'preview_disposition' => PreviewDisposition::class,
            'status' => BulkItemStatus::class,
            'error_category' => ErrorCategory::class,
            'preview_nameservers' => 'array', 'old_nameservers' => 'array', 'target_nameservers' => 'array',
            'excluded_at' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime',
        ];
    }

    public function bulkChange(): BelongsTo
    {
        return $this->belongsTo(BulkChange::class);
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
