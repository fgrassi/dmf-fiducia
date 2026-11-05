<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Grupo de empresas (grupo_empresa): agrupa clientes para filtrar cobranças e a planilha FASA. */
class GrupoEmpresa extends Model
{
    protected $table = 'grupo_empresa';

    protected $primaryKey = 'grupo_empresa_id';

    public $timestamps = false;

    protected $guarded = [];

    public function membros(): HasMany
    {
        return $this->hasMany(GrupoEmpresaMembro::class, 'grupo_empresa_id', 'grupo_empresa_id');
    }
}
