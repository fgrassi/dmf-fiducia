<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GrupoDespesaDespesa extends Model
{
    protected $table = 'grupo_despesa_despesa';

    protected $primaryKey = 'grupo_despesa_despesa_id';

    public $timestamps = false;

    protected $guarded = [];

    public function expense(): BelongsTo
    {
        return $this->belongsTo(FinExpenses::class, 'despesa_id');
    }
}
