<?php

namespace App\Actions\VariedExpenses;

use App\Models\FinDespesasVariadas;
use Illuminate\Support\Facades\DB;

/**
 * removeDespesa (legado): desfaz o vínculo do courier cuja nota é o invoice da despesa (só o primeiro, como o legado) e
 * apaga a despesa.
 */
class DeleteVariedExpense
{
    public function __invoke(FinDespesasVariadas $expense): void
    {
        DB::transaction(function () use ($expense) {
            $courier = filled($expense->invoice)
                ? DB::table('controle_courier')->where('nota', $expense->invoice)->orderBy('controle_courier_id')->value('controle_courier_id')
                : null;

            if ($courier) {
                DB::table('controle_courier')->where('controle_courier_id', $courier)->update(['status_vinculo_nota' => null]);
            }

            $expense->delete();
        });
    }
}
