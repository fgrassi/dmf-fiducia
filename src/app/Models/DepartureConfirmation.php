<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Confirmação de embarque da reserva (departure_confirmation). */
class DepartureConfirmation extends Model
{
    protected $table = 'departure_confirmation';

    protected $primaryKey = 'id';

    public $timestamps = false;

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'usuario_id');
    }
}
