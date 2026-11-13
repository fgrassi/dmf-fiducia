<?php

namespace App\Models;

use App\Models\Concerns\HasReferences;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class Porto extends Model
{
    use HasReferences;

    public const TYPES = ['p' => 'Porto', 'a' => 'Aeroporto', 's' => 'Porto Seco'];

    protected $table = 'porto';

    protected $primaryKey = 'porto_id';

    public $timestamps = false;

    protected $guarded = [];

    /** @var array<string, string|list<string>> */
    protected array $references = [
        'embarque' => ['porto_origem_id', 'porto_destino_id', 'porto_descarga'],
        'embarque_rota' => ['porto_origem_id', 'porto_destino_id'],
        'cotacao' => ['ref_porto_origem', 'ref_porto_destino'],
        'cotacao_codigo' => ['ref_porto_origem', 'ref_porto_destino'],
        'cotacao_expo' => ['porto_origem_id', 'porto_destino_id'],
        'cotacao_impo' => ['porto_origem_id', 'porto_destino_id'],
        'cotacao_impo_aereo' => ['porto_origem_id', 'porto_destino_id'],
        'cotacao_seguro' => ['porto_origem_id', 'porto_destino_id'],
        'cotacao_xls_item' => ['porto_origem_id', 'porto_destino_id'],
        'cotacao_xls_armador_despesa' => 'porto_id',
        'programacao_navio' => ['porto_origem_id', 'porto_destino_id'],
        'transit_time' => ['porto_origem_id', 'porto_destino_id'],
        'agente_contato' => 'porto_id',
        'agente_porto' => 'porto_id',
        'agente_pais_porto' => 'porto_id',
        'cmn_armador_porto' => 'ref_porto',
        'despachante_porto' => 'id_porto',
        'local_retirada' => 'porto_id',
        'matriz_despesa_pessoa' => 'porto_id',
        'transborda' => 'porto_id',
    ];

    public function pais(): BelongsTo
    {
        return $this->belongsTo(Pais::class, 'ref_pais', 'paises_id');
    }

    /** Porto::__toString (legado): nome (país)(uncode). */
    protected function label(): Attribute
    {
        return Attribute::get(fn () => "{$this->nome} (".DB::table('paises')->where('paises_id', $this->ref_pais)->value('uncode').")({$this->uncode})");
    }
}
