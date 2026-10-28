<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinCompaniesRates extends Model
{
    protected $table = 'fin_companies_rates';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $guarded = [];

    public function pessoa(): BelongsTo
    {
        return $this->belongsTo(Pessoa::class, 'ref_pessoa', 'pessoa_id');
    }

    public function rate(): BelongsTo
    {
        return $this->belongsTo(FinCurrenciesRates::class, 'ref_currency_rate');
    }
}
