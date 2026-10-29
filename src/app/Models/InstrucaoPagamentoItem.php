<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstrucaoPagamentoItem extends Model
{
    protected $table = 'instrucao_pagamento_item';

    protected $primaryKey = 'instrucao_pagamento_item_id';

    public $timestamps = false;

    public function instruction(): BelongsTo
    {
        return $this->belongsTo(InstrucaoPagamento::class, 'instrucao_pagamento_id', 'instrucao_pagamento_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(FinPaymentOrderItem::class, 'fin_payment_order_items_id', 'id');
    }
}
