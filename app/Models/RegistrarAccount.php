<?php

namespace App\Models;

use App\Enums\RegistrarConnectionStatus;
use App\Enums\RegistrarEnvironment;
use App\Enums\RegistrarProvider;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RegistrarAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider', 'environment', 'label', 'username', 'api_user', 'client_ipv4', 'credentials', 'is_active',
        'last_test_status', 'last_test_message', 'last_tested_at', 'last_synced_at',
    ];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'provider' => RegistrarProvider::class,
            'environment' => RegistrarEnvironment::class,
            'last_test_status' => RegistrarConnectionStatus::class,
            'credentials' => 'encrypted:array',
            'is_active' => 'boolean',
            'last_tested_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    public function syncRuns(): HasMany
    {
        return $this->hasMany(SyncRun::class);
    }
}
