<?php

namespace App\Models;

use App\Enums\ErrorCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncRunEnrichment extends Model
{
    public const TYPE_NAMESERVERS = 'NAMESERVERS';

    public const TYPE_RENEWAL_PRICES = 'RENEWAL_PRICES';

    public const STATUS_QUEUED = 'QUEUED';

    public const STATUS_RUNNING = 'RUNNING';

    public const STATUS_SUCCEEDED = 'SUCCEEDED';

    public const STATUS_FAILED = 'FAILED';

    public const STATUS_CANCELLED = 'CANCELLED';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'error_category' => ErrorCategory::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(SyncRun::class);
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
