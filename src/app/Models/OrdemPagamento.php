<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/** Ordem de pagamento a despachante, cia aérea ou seguro (módulo ordemPagamentoOutros do legado). */
class OrdemPagamento extends Model
{
    protected $table = 'ordem_pagamento';

    protected $primaryKey = 'ordem_pagamento_id';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'created_at' => 'date',
            'payment_date' => 'date',
        ];
    }

    public function embarque(): BelongsTo
    {
        return $this->belongsTo(Embarque::class, 'ref_embarque', 'embarque_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrdemPagamentoItem::class, 'ordem_pagamento_id', 'ordem_pagamento_id');
    }

    public function instructions(): HasMany
    {
        return $this->hasMany(InstrucaoPagamentoOutros::class, 'ordem_pagamento_id', 'ordem_pagamento_id');
    }

    public function taPayments(): HasMany
    {
        return $this->hasMany(TaBaixa::class, 'ordem_pagamento_id', 'ordem_pagamento_id');
    }

    /** OrdemPagamento::getValorTotal (legado): soma de todos os itens, ligados ou não. */
    public function itemsTotal(): float
    {
        return (float) DB::table('ordem_pagamento_item')->where('ordem_pagamento_id', $this->getKey())->sum('item_value');
    }
}
