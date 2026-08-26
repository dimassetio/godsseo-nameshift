<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NameserverPreset extends Model
{
    protected $fillable = ['name', 'nameservers'];

    protected function casts(): array
    {
        return ['nameservers' => 'array'];
    }
}
