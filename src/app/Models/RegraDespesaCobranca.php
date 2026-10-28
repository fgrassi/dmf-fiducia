<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class RegraDespesaCobranca extends Model
{
    public const TIPOS_EMBARQUE = ['m' => 'MARÍTIMO', 'a' => 'AÉREO', 's' => 'SEGURO', 'd' => 'DESEMBARAÇO'];

    public const IMPOR_EXPOR = ['i' => 'IMPO', 'e' => 'EXPO'];

    protected $table = 'regra_despesa_cobranca';

    protected $primaryKey = 'regra_despesa_cobranca_id';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'obrigatoria' => 'boolean',
        ];
    }

    /** Despesas do grupo (regra_despesa_cobranca_item). */
    public function expenses(): BelongsToMany
    {
        return $this->belongsToMany(FinExpenses::class, 'regra_despesa_cobranca_item', 'regra_despesa_cobranca_id', 'despesa_id');
    }
}
