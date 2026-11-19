<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Envio dos documentos (HBL original) da reserva ao exportador na origem: status 0 pendente, 1 enviado. */
class LiberacaoEmbarqueOrigem extends Model
{
    public const SENT = 1;

    protected $table = 'liberacao_embarque_origem';

    protected $primaryKey = 'liberacao_embarque_id';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['data_liberacao' => 'date', 'updated_at' => 'datetime'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by', 'usuario_id');
    }
}
