<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradingDespachante extends Model
{
    protected $table = 'trading_despachante';

    protected $primaryKey = 'trading_despachante_id';

    public $timestamps = false;

    protected $guarded = [];

    public function broker(): BelongsTo
    {
        return $this->belongsTo(Despachante::class, 'despachante_id', 'despachante_id');
    }
}
