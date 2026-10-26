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
}
