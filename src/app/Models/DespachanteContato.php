<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** status: 1 OK, 0 aguardando aprovação (e-mail fora do domínio), -1 excluído. tipo 'd': desconsolidação. */
class DespachanteContato extends Model
{
    protected $table = 'despachante_contato';

    public $timestamps = false;

    protected $guarded = [];
}
