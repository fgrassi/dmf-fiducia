<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** status: 1 OK, 0 aguardando aprovação (e-mail fora do domínio), -1 excluído. */
class AssessoriaContato extends Model
{
    protected $table = 'assessoria_contato';

    protected $primaryKey = 'assessoria_contato_id';

    public $timestamps = false;

    protected $guarded = [];
}
