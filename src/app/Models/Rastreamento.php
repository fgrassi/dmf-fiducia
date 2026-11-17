<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Linha da rastreabilidade da reserva (rastreamento): o que foi feito, por quem e quando. */
class Rastreamento extends Model
{
    protected $table = 'rastreamento';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['created_at' => 'date'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'usuario_id');
    }
}
