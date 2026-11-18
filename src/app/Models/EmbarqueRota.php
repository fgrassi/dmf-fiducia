<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Alteração de rota do navio da reserva (embarque_rota). */
class EmbarqueRota extends Model
{
    protected $table = 'embarque_rota';

    protected $primaryKey = 'rota_id';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['saida_prevista' => 'date', 'chegada_prevista' => 'date', 'created_at' => 'date', 'data_reg' => 'date'];

    public function navio(): BelongsTo
    {
        return $this->belongsTo(Navio::class, 'navio_id', 'navio_id');
    }

    public function portoOrigem(): BelongsTo
    {
        return $this->belongsTo(Porto::class, 'porto_origem_id', 'porto_id');
    }

    public function portoDestino(): BelongsTo
    {
        return $this->belongsTo(Porto::class, 'porto_destino_id', 'porto_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'usuario_id');
    }

    /** Dias entre a saída e a chegada previstas. */
    public function transitDays(): int
    {
        return (int) $this->saida_prevista->diffInDays($this->chegada_prevista, true);
    }
}
