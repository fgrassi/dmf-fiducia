<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegraDespesaCobranca extends Model
{
    protected $table = 'regra_despesa_cobranca';

    protected $primaryKey = 'regra_despesa_cobranca_id';

    public $timestamps = false;
}
