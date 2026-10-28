<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Cotação com histórico de valores: expõe o último valor lançado (latest_rate) e sua data (latest_date).
 *
 * @property string $valuesTable tabela do histórico
 * @property string $valuesKey coluna do histórico que aponta para a cotação
 */
trait HasRateValues
{
    public function scopeWithLatestValue(Builder $query): Builder
    {
        $latest = DB::table($this->valuesTable)
            ->whereColumn("{$this->valuesTable}.{$this->valuesKey}", $this->getTable().'.id')
            ->orderByDesc('rate_date')
            ->limit(1);

        return $query
            ->addSelect($this->getTable().'.*')
            ->selectSub((clone $latest)->select('rate'), 'latest_rate')
            ->selectSub((clone $latest)->select('rate_date'), 'latest_date');
    }
}
