<?php

namespace App\Models;

use App\Models\Concerns\HasReferences;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cidades (cidades): cidade de um estado. */
class Cidade extends Model
{
    use HasReferences;

    protected $table = 'cidades';

    protected $primaryKey = 'cidades_id';

    public $timestamps = false;

    protected $guarded = [];

    /** @var array<string, string> */
    protected array $references = [
        'pessoa' => 'cidades_id',
        'usuario' => 'cidades_id',
        'porto' => 'cidades_id',
        'destino_final' => 'cidade_id',
    ];

    public function estado(): BelongsTo
    {
        return $this->belongsTo(Estado::class, 'estados_id', 'estados_id');
    }
}
