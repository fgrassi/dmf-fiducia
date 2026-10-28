<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinExpensesMatrix extends Model
{
    protected $table = 'fin_expenses_matrix';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $guarded = [];

    public function armadorPorto(): BelongsTo
    {
        return $this->belongsTo(CmnArmadorPorto::class, 'ref_armador_porto');
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(FinExpenses::class, 'ref_expense');
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(TipoContainer::class, 'ref_equipamento', 'tipo_container_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(CmnCurrency::class, 'ref_currency');
    }
}
