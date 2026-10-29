<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Instrução de pagamento: foto dos itens ligados da ordem no momento em que foi gerada (IP0001/2026). */
class InstrucaoPagamento extends Model
{
    protected $table = 'instrucao_pagamento';

    protected $primaryKey = 'instrucao_pagamento_id';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(FinPaymentOrder::class, 'fin_payment_order_id', 'id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InstrucaoPagamentoItem::class, 'instrucao_pagamento_id', 'instrucao_pagamento_id');
    }

    /** Código impresso: IP + número com 4 dígitos + / + ano de criação. */
    public function code(): string
    {
        return 'IP'.str_pad((string) $this->getKey(), 4, '0', STR_PAD_LEFT).'/'.$this->created_at->format('Y');
    }
}
