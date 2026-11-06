<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComissaoEmbarque extends Model
{
    protected $table = 'comissao_embarque';

    protected $primaryKey = 'comissao_embarque_id';

    public $timestamps = false;

    public function moeda(): BelongsTo
    {
        return $this->belongsTo(CmnCurrency::class, 'moeda_id', 'id');
    }
}
