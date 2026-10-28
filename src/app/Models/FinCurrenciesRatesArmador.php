<?php

namespace App\Models;

use App\Models\Concerns\HasRateValues;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinCurrenciesRatesArmador extends Model
{
    use HasRateValues;

    protected $table = 'fin_currencies_rates_armador';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $guarded = [];

    protected string $valuesTable = 'fin_currencies_rates_armador_values';

    protected string $valuesKey = 'ref_currency_rate_armador';

    public function armador(): BelongsTo
    {
        return $this->belongsTo(Armador::class, 'ref_armador', 'armador_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(CmnCurrency::class, 'ref_currency');
    }

    /** Título do lançamento (legado): armador + " - " + moeda "Nome (símbolo)". */
    protected function label(): Attribute
    {
        return Attribute::get(fn () => $this->armador?->nome.' - '.($this->currency ? "{$this->currency->name} ({$this->currency->symbol})" : ''));
    }
}
