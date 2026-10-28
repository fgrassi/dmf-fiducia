<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinDefaultRates extends Model
{
    protected $table = 'fin_default_rates';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $guarded = [];

    public function rate(): BelongsTo
    {
        return $this->belongsTo(FinCurrenciesRates::class, 'ref_currency_rate');
    }
}
