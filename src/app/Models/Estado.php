<?php

namespace App\Models;

use App\Models\Concerns\HasReferences;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Estados (estados): estado/UF de um país. */
class Estado extends Model
{
    use HasReferences;

    protected $table = 'estados';

    protected $primaryKey = 'estados_id';

    public $timestamps = false;

    protected $guarded = [];

    /** @var array<string, string> */
    protected array $references = [
        'cidades' => 'estados_id',
        'porto' => 'ref_estado',
        'sedes' => 'estado_id',
        'transportadora_estado' => 'estado_id',
        'controle_correio' => 'estados_id',
        'destino_final' => 'estado_id',
    ];

    public function pais(): BelongsTo
    {
        return $this->belongsTo(Pais::class, 'paises_id', 'paises_id');
    }
}
