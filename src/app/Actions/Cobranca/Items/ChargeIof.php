<?php

namespace App\Actions\Cobranca\Items;

use App\Models\FinCharges;
use App\Services\LegacyMath;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Port de Cobranca::deveCalcularIof / getAliquotaIOF / calculaBaseIof (legado). */
class ChargeIof
{
    /** getAliquotaIOF: fixa em 3,5% no legado. */
    private const RATE = 0.035;

    /** VIPAL: exportação sem IOF. */
    private const VIPAL_ID = 4392;

    /** fin_expenses_values.ref_due_to = 7: taxa devida à DMF, sem IOF. */
    public const DUE_TO_DMF = 7;

    /** listDespesaIdsIsentosIof: BL Fee é isento mesmo fora da regra 10. */
    public const BL_FEE_EXPENSES = [11, 951, 959, 734, 906, 863, 888, 1520, 1736, 1739, 1740, 1813];

    /** IOF que a cobrança deve ter: zero se não incide, o valor gravado se manual (ctr_valor_iof), senão base × alíquota. */
    public function amount(FinCharges $charge): float
    {
        if (! $this->applies($charge)) {
            return 0.0;
        }

        return $charge->ctr_valor_iof
            ? (float) $charge->valor_iof
            : $this->calculated($charge);
    }

    /** IOF calculado (base × alíquota) ignorando o valor manual: é o que o recálculo grava. */
    public function calculated(FinCharges $charge): float
    {
        return $this->applies($charge) ? LegacyMath::round($this->base($charge) * self::RATE, 2) : 0.0;
    }

    /** deveCalcularIof: cliente isento ou exportação da VIPAL não pagam IOF. */
    public function applies(FinCharges $charge): bool
    {
        $client = $charge->embarque->cliente;

        if (! $client) {
            return true;
        }

        if ($client->isento_iof) {
            return false;
        }

        return ! ($charge->embarque->impor_expor === 'e' && (int) $client->pessoa_id === self::VIPAL_ID);
    }

    /** calculaBaseIof: soma dos itens não isentos (FinChargesItems::getIsentaIof: BRL, Due To DMF, BL Fee ou despesa com a regra 10). */
    public function base(FinCharges $charge): float
    {
        return (float) DB::table('fin_charges_items as i')
            ->join('fin_expenses_values as v', 'v.id', '=', 'i.ref_expense')
            ->where('i.ref_charge', $charge->id)
            ->where(fn (Builder $q) => $q->whereNull('i.ref_currency')->orWhere('i.ref_currency', '<>', 1))
            ->where(fn (Builder $q) => $q->whereNull('v.ref_due_to')->orWhere('v.ref_due_to', '<>', self::DUE_TO_DMF))
            ->whereNotIn('v.ref_expense', self::BL_FEE_EXPENSES)
            ->whereNotExists(fn (Builder $q) => $q->from('regra_despesa_cobranca_item as r')
                ->whereColumn('r.despesa_id', 'v.ref_expense')
                ->where('r.regra_despesa_cobranca_id', 10))
            ->sum('i.item_value');
    }
}
