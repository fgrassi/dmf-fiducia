<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Reserva guardada em uma caixa (controle_caixa_embarque no legado). */
class ControleCaixaEmbarque extends Model
{
    protected $table = 'controle_caixa_embarque';

    protected $primaryKey = 'controle_caixa_embarque_id';

    public $timestamps = false;

    protected $guarded = [];

    public function box(): BelongsTo
    {
        return $this->belongsTo(ControleCaixa::class, 'controle_caixa_id', 'controle_caixa_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Embarque::class, 'embarque_id', 'embarque_id');
    }
}
