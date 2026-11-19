<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Despesa da matriz do despachante num porto (matriz_despesa_despachante); due_to vazio = vigente. */
class MatrizDespesaDespachante extends Model
{
    protected $table = 'matriz_despesa_despachante';

    public $timestamps = false;

    protected $guarded = [];

    public function brokerPort(): BelongsTo
    {
        return $this->belongsTo(DespachantePorto::class, 'ref_despachante_porto');
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
