<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Transit time combinado por cliente, armador e trecho (transit_time). */
class TransitTime extends Model
{
    protected $table = 'transit_time';

    protected $primaryKey = 'transit_time_id';

    public $timestamps = false;

    protected $guarded = [];

    public function person(): BelongsTo
    {
        return $this->belongsTo(Pessoa::class, 'pessoa_id', 'pessoa_id');
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Armador::class, 'armador_id', 'armador_id');
    }

    public function originPort(): BelongsTo
    {
        return $this->belongsTo(Porto::class, 'porto_origem_id', 'porto_id');
    }

    public function destinationPort(): BelongsTo
    {
        return $this->belongsTo(Porto::class, 'porto_destino_id', 'porto_id');
    }
}
