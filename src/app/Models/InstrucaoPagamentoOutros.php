<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Instrução de pagamento de uma parte (despachante, cia aérea, seguradora) da ordem de Outros: IP0001/2026. */
class InstrucaoPagamentoOutros extends Model
{
    protected $table = 'instrucao_pagamento_outros';

    protected $primaryKey = 'instrucao_pagamento_outros_id';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(OrdemPagamento::class, 'ordem_pagamento_id', 'ordem_pagamento_id');
    }

    /** Código impresso: IP + número com 4 dígitos + / + ano de criação. */
    public function code(): string
    {
        return 'IP'.str_pad((string) $this->getKey(), 4, '0', STR_PAD_LEFT).'/'.$this->created_at->format('Y');
    }

    /** InstrucaoPagamentoOutros::getTotal (legado): soma dos valores dos itens, sem o IOF. */
    public function itemsTotal(): float
    {
        return (float) InstrucaoPagamentoItemOutros::where('instrucao_pagamento_outros_id', $this->getKey())->sum('valor');
    }
}
