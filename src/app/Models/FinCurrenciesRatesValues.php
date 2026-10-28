<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinCurrenciesRatesValues extends Model
{
    protected $table = 'fin_currencies_rates_values';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $guarded = [];
}
