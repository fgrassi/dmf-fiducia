<?php

namespace App\Models;

use App\Models\Concerns\HasRateValues;
use App\Models\Concerns\HasReferences;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinCurrenciesRates extends Model
{
    use HasRateValues;
    use HasReferences;

    protected $table = 'fin_currencies_rates';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $guarded = [];

    protected string $valuesTable = 'fin_currencies_rates_values';

    protected string $valuesKey = 'ref_currency_rate';

    /** @var array<string, string> */
    protected array $references = [
        'fin_currencies_rates' => 'ref_currency_rate',
        'fin_currencies_rates_armador_payment' => 'fin_currencies_rates_id',
        'fin_default_rates' => 'ref_currency_rate',
        'fin_companies_rates' => 'ref_currency_rate',
    ];

    public function base(): BelongsTo
    {
        return $this->belongsTo(self::class, 'ref_currency_rate');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(CmnCurrency::class, 'ref_currency');
    }

    /** FinCurrenciesRates::__toString (legado): nome + " - " + moeda "Nome (símbolo)". */
    protected function label(): Attribute
    {
        return Attribute::get(fn () => $this->name.' - '.($this->currency ? "{$this->currency->name} ({$this->currency->symbol})" : ''));
    }
}
