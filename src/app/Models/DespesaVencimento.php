<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Vencimento fixo mensal de um fornecedor/categoria; o aviso de 3 dias antes sai de ExpenseDueNotice. */
class DespesaVencimento extends Model
{
    protected $table = 'despesa_vencimento';

    protected $primaryKey = 'despesa_vencimento_id';

    public $timestamps = false;

    protected $guarded = [];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Fornecedor::class, 'fornecedor_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CategoriaDespesa::class, 'categoria_despesa_id');
    }
}
