<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Nota variada de agente (fin_despesas_variadas no legado): crédito ou débito avulso, ligado a uma reserva só pelo texto do booking. */
class FinDespesasVariadas extends Model
{
    /** FinDespesasVariadas::getTipo (legado). */
    public const NOTE_TYPES = ['1' => 'Crédito', '2' => 'Débito'];

    /** Tipo do anexo da reserva: 35 para o crédito, 27 para o débito (saveAction do legado). */
    public const FILE_TYPE_CREDIT = 35;

    public const FILE_TYPE_DEBIT = 27;

    protected $table = 'fin_despesas_variadas';

    protected $primaryKey = 'fin_despesas_variadas_id';

    public $timestamps = false;

    protected $guarded = [];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Pessoa::class, 'agente_id', 'pessoa_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(CmnCurrency::class, 'cmn_currencies_id', 'id');
    }
}
