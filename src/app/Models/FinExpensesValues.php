<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinExpensesValues extends Model
{
    protected $table = 'fin_expenses_values';

    protected $primaryKey = 'id';

    public $timestamps = false;

    public function despesa(): BelongsTo
    {
        return $this->belongsTo(FinExpenses::class, 'ref_expense', 'id');
    }

    public function dueTo(): BelongsTo
    {
        return $this->belongsTo(FinExpensesValuesDueTo::class, 'ref_due_to', 'id');
    }

    public function bl(): BelongsTo
    {
        return $this->belongsTo(Bl::class, 'ref_bl', 'bl_id');
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(TipoContainer::class, 'ref_equipment', 'tipo_container_id');
    }

    public function buyingCurrency(): BelongsTo
    {
        return $this->belongsTo(CmnCurrency::class, 'ref_currency_br', 'id');
    }

    public function sellingCurrency(): BelongsTo
    {
        return $this->belongsTo(CmnCurrency::class, 'ref_currency_sr', 'id');
    }
}
