<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Programação de saída de um navio entre dois portos (programacao_navio). */
class ProgramacaoNavio extends Model
{
    protected $table = 'programacao_navio';

    protected $primaryKey = 'programaca_navio_id';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'saida_prevista' => 'date',
        'chegada_prevista' => 'date',
    ];

    public function ship(): BelongsTo
    {
        return $this->belongsTo(Navio::class, 'navio_id', 'navio_id');
    }

    public function originPort(): BelongsTo
    {
        return $this->belongsTo(Porto::class, 'porto_origem_id', 'porto_id');
    }

    public function destinationPort(): BelongsTo
    {
        return $this->belongsTo(Porto::class, 'porto_destino_id', 'porto_id');
    }

    /** TT do legado: dias entre a saída e a chegada previstas. */
    public function transitDays(): int
    {
        return (int) abs($this->saida_prevista->diffInDays($this->chegada_prevista));
    }
}
