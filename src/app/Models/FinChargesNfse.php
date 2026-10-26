<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinChargesNfse extends Model
{
    protected $table = 'fin_charges_nfse';

    protected $primaryKey = 'fin_charges_nfse_id';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
