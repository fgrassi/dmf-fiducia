<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Reserva ligada a um courier (ctr_courier_embarque no legado). */
class CtrCourierEmbarque extends Model
{
    protected $table = 'ctr_courier_embarque';

    protected $primaryKey = 'ctr_courier_embarque_id';

    public $timestamps = false;

    protected $guarded = [];

    public function courier(): BelongsTo
    {
        return $this->belongsTo(ControleCourier::class, 'controle_courier_id', 'controle_courier_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Embarque::class, 'embarque_id', 'embarque_id');
    }
}
