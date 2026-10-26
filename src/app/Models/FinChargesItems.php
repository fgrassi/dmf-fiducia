<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinChargesItems extends Model
{
    protected $table = 'fin_charges_items';

    protected $primaryKey = 'id';

    public $timestamps = false;

    public function currency(): BelongsTo
    {
        return $this->belongsTo(CmnCurrency::class, 'ref_currency', 'id');
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(FinExpensesValues::class, 'ref_expense', 'id');
    }
}
