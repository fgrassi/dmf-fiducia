<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Item da ordem (Outros): uma despesa da reserva (ref_expense = fin_expenses_values.id) com valor, taxa e índice. */
class OrdemPagamentoItem extends Model
{
    protected $table = 'ordem_pagamento_item';

    protected $primaryKey = 'ordem_pagamento_item_id';

    public $timestamps = false;

    public function order(): BelongsTo
    {
        return $this->belongsTo(OrdemPagamento::class, 'ordem_pagamento_id', 'ordem_pagamento_id');
    }
}
