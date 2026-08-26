<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DomainMutationReservation extends Model
{
    protected $guarded = [];

    protected $primaryKey = 'domain_id';

    public $incrementing = false;
}
