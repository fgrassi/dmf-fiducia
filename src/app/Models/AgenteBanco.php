<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Conta bancária de um agente (agente_banco no legado): onde a DMF paga o agente; vai no extrato de notas. */
class AgenteBanco extends Model
{
    protected $table = 'agente_banco';

    protected $primaryKey = 'agente_banco_id';

    public $timestamps = false;

    protected $guarded = [];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Pessoa::class, 'agente_id', 'pessoa_id');
    }
}
