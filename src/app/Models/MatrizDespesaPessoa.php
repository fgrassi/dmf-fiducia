<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Despesa padrão de um cliente ou agente (matriz_despesa_pessoa): a venda (S/R) e, para o cliente, a compra (B/R) que a
 * reserva herda, por despesa, cobrança, tipo de reserva, equipamento e porto. `due_to` vazio = vigente.
 */
class MatrizDespesaPessoa extends Model
{
    protected $table = 'matriz_despesa_pessoa';

    public $timestamps = false;

    protected $guarded = [];

    public function pessoa(): BelongsTo
    {
        return $this->belongsTo(Pessoa::class, 'pessoa_id', 'pessoa_id');
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(FinExpenses::class, 'ref_expense');
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(TipoContainer::class, 'ref_equipamento', 'tipo_container_id');
    }

    public function port(): BelongsTo
    {
        return $this->belongsTo(Porto::class, 'porto_id', 'porto_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(CmnCurrency::class, 'ref_currency');
    }

    public function purchaseCurrency(): BelongsTo
    {
        return $this->belongsTo(CmnCurrency::class, 'moeda_compra_id');
    }
}
