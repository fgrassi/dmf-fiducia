<?php

namespace App\Actions\AccountingCovers;

use App\Models\CapaProcesso;
use Illuminate\Support\Facades\DB;

/**
 * remove (legado): exclui a capa com itens e resumo e devolve às cobranças as flags de IOF, desconto, juros e taxa.
 * Diferenças do legado (D13): também zera a flag da multa e solta as baixas de TA presas aos itens de despesa.
 */
class DeleteCover
{
    public function __invoke(CapaProcesso $cover): void
    {
        DB::transaction(function () use ($cover) {
            $receiptItems = DB::table('capa_processo_receita_item')->where('capa_processo_id', $cover->getKey());
            $expenseItems = DB::table('capa_processo_despesa_item')->where('capa_processo_id', $cover->getKey());

            $chargeIds = DB::table('fin_charges_items')
                ->whereIn('ref_expense', (clone $receiptItems)->select('fin_expenses_values_id')->union((clone $expenseItems)->select('fin_expenses_values_id')))
                ->pluck('ref_charge');

            DB::table('fin_charges')->whereIn('id', $chargeIds)->update([
                'ctr_valor_iof' => null,
                'ctr_desconto' => null,
                'ctr_juros' => null,
                'ctr_tax_boleto' => null,
                'ctr_fine' => 0,
            ]);

            DB::table('ta_baixa')
                ->whereIn('capa_processo_despesa_item_id', (clone $expenseItems)->select('capa_processo_despesa_item_id'))
                ->update(['capa_processo_despesa_item_id' => null]);

            $receiptItems->delete();
            $expenseItems->delete();
            DB::table('resumo_resultado')->where('capa_processo_id', $cover->getKey())->delete();
            $cover->delete();
        });
    }
}
