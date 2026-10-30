<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Pasta (mês) das capas de contabilidade (capa_processo_arquivo no legado). */
class CapaProcessoArquivo extends Model
{
    public const STATUSES = [0 => 'Fechado', 1 => 'Aberto'];

    protected $table = 'capa_processo_arquivo';

    protected $primaryKey = 'capa_processo_arquivo_id';

    public $timestamps = false;

    protected $guarded = [];
}
