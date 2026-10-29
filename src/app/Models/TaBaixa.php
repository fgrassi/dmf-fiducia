<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Baixa de TA (pagamento parcial da despesa TA da reserva, em moeda estrangeira); criada pelo relatório de TA no legado. */
class TaBaixa extends Model
{
    protected $table = 'ta_baixa';

    protected $primaryKey = 'ta_baixa_id';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['data_pagamento' => 'date'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(OrdemPagamento::class, 'ordem_pagamento_id', 'ordem_pagamento_id');
    }

    /** TaBaixa::podeAlterar (legado): baixa já vinculada a uma capa não muda. */
    public function canChange(): bool
    {
        return ! $this->capa_processo_despesa_item_id;
    }
}
