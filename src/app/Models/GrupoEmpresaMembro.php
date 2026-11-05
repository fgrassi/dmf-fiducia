<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Empresa (pessoa) que pertence a um grupo de empresas. */
class GrupoEmpresaMembro extends Model
{
    protected $table = 'grupo_empresa_membro';

    protected $primaryKey = 'grupo_empresa_membro_id';

    public $timestamps = false;

    protected $guarded = [];

    public function pessoa(): BelongsTo
    {
        return $this->belongsTo(Pessoa::class, 'pessoa_id', 'pessoa_id');
    }
}
