<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Aplicacao extends Model
{
    protected $table = 'aplicacao';

    protected $primaryKey = 'aplicacao_id';

    public $timestamps = false;

    /** Empresas com acesso à aplicação. */
    public function companyLinks(): HasMany
    {
        return $this->hasMany(PessoaAplicacao::class, 'aplicacao_id', 'aplicacao_id');
    }
}
