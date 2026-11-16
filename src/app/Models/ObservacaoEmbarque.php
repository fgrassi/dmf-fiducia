<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Observação/lembrete livre da reserva (observacoes_embarque). */
class ObservacaoEmbarque extends Model
{
    protected $table = 'observacoes_embarque';

    protected $primaryKey = 'observacoes_embarque_id';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['created_at' => 'datetime'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'usuario_id');
    }
}
