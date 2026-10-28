<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinCurrenciesRatesArmadorPaymentValues extends Model
{
    protected $table = 'fin_currencies_rates_armador_payment_values';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $guarded = [];
}
