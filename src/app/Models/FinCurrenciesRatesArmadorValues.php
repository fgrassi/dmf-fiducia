<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinCurrenciesRatesArmadorValues extends Model
{
    protected $table = 'fin_currencies_rates_armador_values';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $guarded = [];
}
