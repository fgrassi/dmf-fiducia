<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepositoGarantia extends Model
{
    protected $table = 'deposito_garantia';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $guarded = [];

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(TipoContainer::class, 'ref_equipment', 'tipo_container_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(CmnCurrency::class, 'ref_currency');
    }
}
