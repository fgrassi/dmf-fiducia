<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinExpenses extends Model
{
    protected $table = 'fin_expenses';

    protected $primaryKey = 'id';

    public $timestamps = false;

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
