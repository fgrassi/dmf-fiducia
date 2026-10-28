<?php

namespace App\Models;

use App\Models\Concerns\HasReferences;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinExpenses extends Model
{
    use HasReferences;

    protected $table = 'fin_expenses';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $guarded = [];

    /** @var array<string, string> */
    protected array $references = [
        'fin_expenses_values' => 'ref_expense',
        'fin_expenses_matrix' => 'ref_expense',
        'fin_charges_items' => 'ref_expense',
    ];

    public function currency(): BelongsTo
    {
        return $this->belongsTo(CmnCurrency::class, 'ref_currency');
    }

    public function unity(): BelongsTo
    {
        return $this->belongsTo(FinExpensesUnity::class, 'ref_unity');
    }

    /** FinExpenses::__toString (legado): nome + " / sigla da unidade". */
    protected function label(): Attribute
    {
        return Attribute::get(fn () => $this->name.($this->unity?->acronym ? ' / '.$this->unity->acronym : ''));
    }
}
