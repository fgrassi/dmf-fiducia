<?php

namespace App\Actions\Booking;

use App\Models\FinExpensesValues;

/**
 * vendeCusto (legado, "Vender custo" na linha da despesa): iguala a venda ao custo, valor e moeda.
 * Como no legado, não grava autoria, histórico nem devolve a reserva à fila de cobrança.
 */
class SellCost
{
    public function __invoke(FinExpensesValues $expense): FinExpensesValues
    {
        $expense->forceFill([
            'selling_rate' => $expense->buying_rate,
            'ref_currency_sr' => $expense->ref_currency_br,
        ])->save();

        return $expense;
    }
}
