<?php

namespace App\Models;

use App\Models\Concerns\HasReferences;
use Illuminate\Database\Eloquent\Model;

/** Paises (paises): país com UN Code, usado em estados, portos, pessoas e agentes. */
class Pais extends Model
{
    use HasReferences;

    protected $table = 'paises';

    protected $primaryKey = 'paises_id';

    public $timestamps = false;

    protected $guarded = [];

    /** @var array<string, string> */
    protected array $references = [
        'estados' => 'paises_id',
        'porto' => 'ref_pais',
        'pessoa' => 'paises_id',
        'agente_pais' => 'pais_id',
        'instrucao_especial' => 'paises_id',
        'destino_final' => 'pais_id',
        'fin_expenses_matrix' => 'pais_origem_id',
    ];
}
