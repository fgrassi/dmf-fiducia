<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Próximo contato combinado com o cliente (visita_cliente); status 0 é o contato em aberto. */
class VisitaCliente extends Model
{
    protected $table = 'visita_cliente';

    protected $primaryKey = 'visita_cliente_id';

    public $timestamps = false;

    protected $guarded = [];
}
