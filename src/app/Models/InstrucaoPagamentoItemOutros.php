<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstrucaoPagamentoItemOutros extends Model
{
    protected $table = 'instrucao_pagamento_item_outros';

    protected $primaryKey = 'instrucao_pagamento_item_outros_id';

    public $timestamps = false;
}
