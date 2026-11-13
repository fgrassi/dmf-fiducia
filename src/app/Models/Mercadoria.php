<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Mercadoria da reserva (mercadoria): carga, equipamento, pesos e medidas. */
class Mercadoria extends Model
{
    protected $table = 'mercadoria';

    protected $primaryKey = 'mercadoria_id';

    public $timestamps = false;

    protected $guarded = [];

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(TipoContainer::class, 'tipo_container_id', 'tipo_container_id');
    }
}
