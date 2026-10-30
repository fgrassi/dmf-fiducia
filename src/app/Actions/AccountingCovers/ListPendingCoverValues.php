<?php

namespace App\Actions\AccountingCovers;

use App\Actions\Booking\SaveExpense;
use App\Models\Embarque;
use App\Services\Cobranca\ChargeDetails;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * loadValoresReserva (legado): o que da reserva (e das "outras" com o mesmo MBL ou booking) ainda não entrou em nenhuma capa.
 *
 * Diferenças do legado: despesas por unidade (ref_unity = 5) repetidas contam por diferença de multiconjunto (o `$acum` do legado
 * nunca zera e esconde itens de outras despesas); IOF de cobrança só aparece se positivo, também com "outras" reservas.
 *
 * @phpstan-type Row array{id: int, name: string, value: float}
 */
class ListPendingCoverValues
{
    private const TA_EXPENSE = SaveExpense::HIDDEN_FLAGS_EXPENSE;

    private const POSTAL_DUE_TO = 10;

    private const PER_UNIT = 5;

    public function __construct(private ChargeDetails $details) {}

    /**
     * @return array{
     *     revenues: list<Row>,
     *     expenses: list<Row&array{ta_payment_id?: int}>,
     *     covered_expenses: list<Row>,
     *     purchase_iof: list<float>,
     *     iof: list<array{id: int, value: float}>,
     *     discount: list<array{id: int, value: float}>,
     *     interest: list<array{id: int, value: float}>,
     *     bank_fee: list<array{id: int, value: float}>,
     *     fine: list<array{id: int, value: float}>,
     * }
     */
    public function __invoke(Embarque $booking): array
    {
        $ids = $this->bookingIds($booking);
        $charges = DB::table('fin_charges')->whereIn('ref_booking', $ids)->orderBy('id')->get();
        [$orderedExpenses, $coveredExpenses] = $this->otherOrderItems($ids);

        return [
            'revenues' => $this->revenues($ids),
            'expenses' => [...$this->expenses($ids), ...$this->postal($ids), ...$orderedExpenses, ...$this->taPayments($ids)],
            'covered_expenses' => $coveredExpenses,
            'purchase_iof' => $this->purchaseIof($ids),
            'iof' => $this->unflagged($charges, 'ctr_valor_iof', 'valor_iof'),
            'discount' => $this->unflagged($charges, 'ctr_desconto', 'discount'),
            'interest' => $this->unflagged($charges, 'ctr_juros', 'interest'),
            'bank_fee' => $this->unflagged($charges, 'ctr_tax_boleto', 'tax_boleto'),
            'fine' => $this->unflagged($charges, 'ctr_fine', 'fine'),
        ];
    }

    /** @return list<int> a reserva e as "outras" que dividem MBL ou booking com ela. */
    private function bookingIds(Embarque $booking): array
    {
        $others = blank($booking->master_bl)
            ? []
            : Embarque::where('embarque_id', '<>', $booking->getKey())
                ->where(fn ($q) => $q->where('master_bl', $booking->master_bl)->orWhere('booking', $booking->booking))
                ->pluck('embarque_id')->all();

        return array_values(array_map('intval', [$booking->getKey(), ...$others]));
    }

    /** @return list<array{id: int, value: float}> */
    private function unflagged(Collection $charges, string $flag, string $value): array
    {
        return $charges->filter(fn ($c) => ! $c->{$flag} && $c->{$value} > 0)
            ->map(fn ($c) => ['id' => (int) $c->id, 'value' => (float) $c->{$value}])->values()->all();
    }

    /** @param  list<int>  $ids */
    private function revenues(array $ids): array
    {
        $items = fn () => DB::table('fin_charges_items as fci')
            ->join('fin_expenses_values as fev', 'fev.id', '=', 'fci.ref_expense')
            ->join('fin_expenses as fe', 'fe.id', '=', 'fev.ref_expense')
            ->join('fin_charges as fc', 'fc.id', '=', 'fci.ref_charge')
            ->whereIn('fc.ref_booking', $ids);

        $grouped = $items()->where('fe.ref_unity', '<>', self::PER_UNIT)
            ->whereNotIn('fev.id', DB::table('capa_processo_receita_item')->select('fin_expenses_values_id'))
            ->groupBy('fev.id')->selectRaw('fev.id as id, SUM(fci.item_value) as value')->get();

        $perUnit = $items()->where('fe.ref_unity', self::PER_UNIT)->select('fev.id as id', 'fci.item_value as value')->get();

        return $this->named([...$grouped, ...$this->notYetCovered($perUnit, 'capa_processo_receita_item')]);
    }

    /** @param  list<int>  $ids */
    private function expenses(array $ids): array
    {
        $items = fn () => DB::table('fin_payment_order_items as fpoi')
            ->join('fin_expenses_values as fev', 'fev.id', '=', 'fpoi.ref_expense')
            ->join('fin_expenses as fe', 'fe.id', '=', 'fev.ref_expense')
            ->join('fin_payment_orders as fpo', 'fpo.id', '=', 'fpoi.ref_payment_order')
            ->whereIn('fpo.ref_embarque', $ids)
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('fin_payment_order_items_disabled as d')
                ->whereColumn('d.ref_fin_payment_order', 'fpoi.ref_payment_order')
                ->whereColumn('d.ref_expense', 'fpoi.ref_expense')
                ->where('d.status', 0));

        $grouped = $items()->where('fe.ref_unity', '<>', self::PER_UNIT)
            ->whereNotIn('fev.id', DB::table('capa_processo_despesa_item')->select('fin_expenses_values_id'))
            ->groupBy('fev.id', 'fpo.payment_date')->selectRaw('fev.id as id, SUM(fpoi.item_value) as value')->get();

        $perUnit = $items()->where('fe.ref_unity', self::PER_UNIT)->select('fev.id as id', 'fpoi.item_value as value')->get();

        return $this->named([...$grouped, ...$this->notYetCovered($perUnit, 'capa_processo_despesa_item')]);
    }

    /**
     * A primeira despesa de correio (due to 10) da reserva, pelo valor de compra.
     *
     * @param  list<int>  $ids
     */
    private function postal(array $ids): array
    {
        $postal = DB::table('fin_expenses_values')->whereIn('ref_embarque', $ids)->where('ref_due_to', self::POSTAL_DUE_TO)->orderBy('id')->first();

        if (! $postal || DB::table('capa_processo_despesa_item')->where('fin_expenses_values_id', $postal->id)->exists()) {
            return [];
        }

        return $this->named([(object) ['id' => $postal->id, 'value' => $postal->buying_rate]]);
    }

    /**
     * Itens habilitados das ordens "Outros": sem capa viram despesa; com capa só ficam à mostra (despesas_capa).
     *
     * @param  list<int>  $ids
     * @return array{0: list<Row>, 1: list<Row>}
     */
    private function otherOrderItems(array $ids): array
    {
        $items = DB::table('ordem_pagamento_item as opi')
            ->join('ordem_pagamento as op', 'op.ordem_pagamento_id', '=', 'opi.ordem_pagamento_id')
            ->join('fin_expenses_values as fev', 'fev.id', '=', 'opi.ref_expense')
            ->whereIn('op.ref_embarque', $ids)
            ->where('opi.status', 1)
            ->where('fev.ref_expense', '<>', self::TA_EXPENSE)
            ->orderBy('opi.ordem_pagamento_item_id')
            ->get(['fev.id as id', 'opi.item_value as value']);

        $inCover = DB::table('capa_processo_despesa_item')->whereIn('fin_expenses_values_id', $items->pluck('id'))->pluck('fin_expenses_values_id')->flip();

        return [
            $this->named($items->reject(fn ($i) => $inCover->has($i->id))->all()),
            $this->named($items->filter(fn ($i) => $inCover->has($i->id))->all()),
        ];
    }

    /**
     * Baixas de TA ainda sem capa, uma linha por baixa, pelo valor pago.
     *
     * @param  list<int>  $ids
     */
    private function taPayments(array $ids): array
    {
        $payments = DB::table('ta_baixa as tb')
            ->join('fin_expenses_values as fev', 'fev.id', '=', 'tb.fin_expenses_values_id')
            ->whereIn('tb.embarque_id', $ids)
            ->whereNull('tb.capa_processo_despesa_item_id')
            ->where('fev.ref_expense', self::TA_EXPENSE)
            ->orderBy('tb.data_pagamento')->orderBy('tb.ta_baixa_id')
            ->get(['tb.fin_expenses_values_id as id', 'tb.valor_pago as value', 'tb.ta_baixa_id']);

        return array_map(
            fn (array $row, object $payment) => $row + ['ta_payment_id' => (int) $payment->ta_baixa_id],
            $this->named($payments->all()),
            $payments->all(),
        );
    }

    /**
     * Soma do IOF de compra das ordens dos dois módulos, uma linha por módulo.
     *
     * @param  list<int>  $ids
     * @return list<float>
     */
    private function purchaseIof(array $ids): array
    {
        return collect(['fin_payment_orders', 'ordem_pagamento'])
            ->map(fn (string $table) => (float) DB::table($table)->whereIn('ref_embarque', $ids)->where('valor_iof', '>', 0)->sum('valor_iof'))
            ->filter(fn (float $total) => $total > 0)->values()->all();
    }

    /**
     * Para as despesas por unidade: tantas linhas quantas sobram depois de descontar as que já entraram em capa com o mesmo valor.
     *
     * @param  Collection<int, object{id: int, value: mixed}>  $rows
     * @return list<object>
     */
    private function notYetCovered(Collection $rows, string $itemsTable): array
    {
        $covered = DB::table($itemsTable)->whereIn('fin_expenses_values_id', $rows->pluck('id')->unique())
            ->get(['fin_expenses_values_id as id', 'valor'])
            ->countBy(fn ($i) => $this->key($i->id, $i->valor))->all();

        return $rows->filter(function ($row) use (&$covered) {
            $key = $this->key($row->id, $row->value);

            if (($covered[$key] ?? 0) > 0) {
                $covered[$key]--;

                return false;
            }

            return true;
        })->values()->all();
    }

    private function key(int|string $id, mixed $value): string
    {
        return $id.'|'.round((float) $value, 2);
    }

    /**
     * @param  array<int, object{id: int, value: mixed}>  $rows
     * @return list<Row>
     */
    private function named(array $rows): array
    {
        return array_values(array_map(fn ($row) => [
            'id' => (int) $row->id,
            'name' => $this->details->expenseLabel($this->labelSource((int) $row->id)),
            'value' => (float) $row->value,
        ], $rows));
    }

    private function labelSource(int $expenseValueId): object
    {
        return DB::table('fin_expenses_values as v')
            ->join('fin_expenses as e', 'e.id', '=', 'v.ref_expense')
            ->leftJoin('fin_expenses_unities as u', 'u.id', '=', 'e.ref_unity')
            ->where('v.id', $expenseValueId)
            ->first(['v.ref_equipment', 'v.ref_bl as v_bl', 'v.ref_container', 'e.name as expense_name', 'u.acronym as unity_name']);
    }
}
