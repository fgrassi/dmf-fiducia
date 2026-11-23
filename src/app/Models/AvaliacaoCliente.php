<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Avaliação do cliente por tipo de follow up (avaliacao_cliente); status 0 é a avaliação atual. */
class AvaliacaoCliente extends Model
{
    protected $table = 'avaliacao_cliente';

    protected $primaryKey = 'avaliacao_cliente_id';

    public $timestamps = false;

    protected $guarded = [];
}
