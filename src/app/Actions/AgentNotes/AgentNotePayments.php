<?php

namespace App\Actions\AgentNotes;

use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * baixaNota (legado) de controleCreditDebitAgente e controleNotas: dá baixa nas notas marcadas (status_pagamento = 1 e a
 * data) ou desfaz a baixa (status_pagamento = 0 e data nula). As chaves são as de AgentNoteLists ("c_12", "d_5", "v_7").
 * Chave de nota que não existe é ignorada, para o lote não parar no meio. Como no legado, não confere se a nota já estava
 * baixada.
 */
class AgentNotePayments
{
    /** [tabela, chave primária] de cada tipo de nota. */
    private const TABLES = [
        'c' => ['fin_credit_notes', 'id'],
        'd' => ['fin_debit_notes', 'id'],
        'v' => ['fin_despesas_variadas', 'fin_despesas_variadas_id'],
    ];

    /** @param  list<string>  $keys */
    public function settle(array $keys, ?string $date): void
    {
        if (! filled($date) || ! Carbon::canBeCreatedFromFormat($date, 'Y-m-d')) {
            throw new DomainException('Informe a data da baixa.');
        }

        $this->apply($keys, ['status_pagamento' => 1, 'data_pagamento' => $date]);
    }

    /** @param  list<string>  $keys */
    public function reopen(array $keys): void
    {
        $this->apply($keys, ['status_pagamento' => 0, 'data_pagamento' => null]);
    }

    /**
     * @param  list<string>  $keys
     * @param  array<string, mixed>  $values
     */
    private function apply(array $keys, array $values): void
    {
        $idsByType = collect($keys)
            ->map(fn ($key) => explode('_', (string) $key, 2) + [1 => ''])
            ->filter(fn (array $parts) => isset(self::TABLES[$parts[0]]) && ctype_digit($parts[1]))
            ->groupBy(0)
            ->map(fn ($parts) => $parts->pluck(1)->all());

        DB::transaction(function () use ($idsByType, $values) {
            foreach ($idsByType as $type => $ids) {
                [$table, $primaryKey] = self::TABLES[$type];

                DB::table($table)->whereIn($primaryKey, $ids)->update($values);
            }
        });
    }
}
