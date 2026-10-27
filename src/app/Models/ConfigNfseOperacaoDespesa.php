<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConfigNfseOperacaoDespesa extends Model
{
    protected $table = 'config_nfse_operacao_despesa';

    protected $primaryKey = 'config_nfse_operacao_despesa_id';

    public $timestamps = false;

    protected $guarded = [];

    public function expense(): BelongsTo
    {
        return $this->belongsTo(FinExpenses::class, 'fin_expenses_id');
    }
}
