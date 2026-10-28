<?php

namespace App\Models;

use App\Models\Concerns\HasRateValues;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinCurrenciesRatesArmadorPayment extends Model
{
    use HasRateValues;

    /** FinCurrenciesRatesArmadorPaymentTable::$types (legado). */
    public const TYPES = [
        'e' => 'Export',
        'i' => 'Import',
    ];

    protected $table = 'fin_currencies_rates_armador_payment';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $guarded = [];

    protected string $valuesTable = 'fin_currencies_rates_armador_payment_values';

    protected string $valuesKey = 'ref_currency_rate_armador';

    /** preSave do legado: o nome é sempre "armador (tipo)". */
    protected static function booted(): void
    {
        static::saving(function (self $payment) {
            $payment->name = $payment->armador()->value('nome').' ('.(self::TYPES[$payment->type] ?? '').')';
        });
    }

    public function armador(): BelongsTo
    {
        return $this->belongsTo(Armador::class, 'ref_armador', 'armador_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(CmnCurrency::class, 'ref_currency');
    }

    public function rate(): BelongsTo
    {
        return $this->belongsTo(FinCurrenciesRates::class, 'fin_currencies_rates_id');
    }

    /** Título do lançamento (legado): armador + " - " + moeda (só o nome) + " (tipo)". */
    protected function label(): Attribute
    {
        return Attribute::get(fn () => $this->armador?->nome.' - '.$this->currency?->name.' ('.(self::TYPES[$this->type] ?? '').')');
    }
}
