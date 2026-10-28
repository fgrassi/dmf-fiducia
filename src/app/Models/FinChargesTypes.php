<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinChargesTypes extends Model
{
    protected $table = 'fin_charges_types';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $guarded = [];

    public function charges(): HasMany
    {
        return $this->hasMany(FinCharges::class, 'ref_type');
    }
}
