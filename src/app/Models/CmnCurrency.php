<?php

namespace App\Models;

use App\Models\Concerns\HasReferences;
use Illuminate\Database\Eloquent\Model;

class CmnCurrency extends Model
{
    use HasReferences;

    protected $table = 'cmn_currencies';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $guarded = [];

    /** @var array<string, string> */
    protected array $references = [
        'fin_expenses' => 'ref_currency',
        'fin_expenses_matrix' => 'ref_currency',
        'fin_expenses_values' => 'ref_currency_br',
        'fin_charges_items' => 'ref_currency',
        'fin_currencies_rates' => 'ref_currency',
        'fin_currencies_rates_armador' => 'ref_currency',
        'fin_currencies_rates_armador_payment' => 'ref_currency',
        'cotacao_item' => 'ref_currency',
        'despesa' => 'moeda_id',
    ];
}
