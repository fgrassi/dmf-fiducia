<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Categoria das frases do rastreamento (frase_categoria); cada usuário só vê as categorias liberadas para ele. */
class FraseCategoria extends Model
{
    protected $table = 'frase_categoria';

    public $timestamps = false;

    protected $guarded = [];

    /** Em uso: frase ou usuário vinculado (não pode excluir). */
    public function inUse(): bool
    {
        return FraseTransborda::where('categoria_id', $this->getKey())->exists()
            || DB::table('usuario_frase_categoria')->where('ref_categoria_frase', $this->getKey())->exists();
    }
}
