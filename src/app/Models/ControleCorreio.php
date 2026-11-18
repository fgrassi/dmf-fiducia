<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Envio pelo Correio (Sedex) de documentos de uma ou mais reservas (controle_correio). */
class ControleCorreio extends Model
{
    protected $table = 'controle_correio';

    protected $primaryKey = 'controle_correio_id';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['data_envio' => 'date'];

    public function estado(): BelongsTo
    {
        return $this->belongsTo(Estado::class, 'estados_id', 'estados_id');
    }
}
