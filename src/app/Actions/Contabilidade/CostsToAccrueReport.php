<?php

namespace App\Actions\Contabilidade;

use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * contabilidade/custosApropriar (legado), "Pagos e não recebidos": reservas com ordem de pagamento (armador ou outros) paga no
 * período e ao menos uma IC ainda com saldo a receber na data final.
 *
 * - Pago: itens das ordens pagas no período;
 * - Apropriado: despesas da capa da reserva (R$); sem capa, o custo de compra da grade (em R$ direto; em outra moeda, o já pago);
 * - Saldo: pago − apropriado.
 */
class CostsToAccrueReport
{
    private const CHUNK = 400;

    /**
     * @param  array{data_ini?: ?string, data_fim?: ?string}  $input
     * @return array{rows: list<array<string, mixed>>, totals: array<string, float>}
     *
     * @throws DomainException período inválido
     */
    public function __invoke(array $input): array
    {
        [$start, $end] = ReportPeriod::parse($input);

        $paid = $this->paidByBooking($start, $end);
        $bookingIds = $this->withOpenCharge(array_keys($paid), $end);
        $accrued = $this->accruedByBooking($bookingIds);

        $rows = collect($bookingIds)
            ->map(fn (int $id) => [
                'cliente' => $paid[$id]['cliente'],
                'reserva_data' => $paid[$id]['reserva_data'],
                'reserva_numero' => $paid[$id]['reserva_numero'],
                'pago' => round($paid[$id]['pago'], 2),
                'apropriado' => round($accrued[$id], 2),
                'saldo' => round($paid[$id]['pago'] - $accrued[$id], 2),
            ])
            ->sort(fn (array $a, array $b) => strcasecmp($a['cliente'], $b['cliente']) ?: strcmp($a['reserva_numero'], $b['reserva_numero']) ?: strcmp((string) $a['reserva_data'], (string) $b['reserva_data']))
            ->values();

        return ['rows' => $rows->all(), 'totals' => [
            'pago' => round($rows->sum('pago'), 2),
            'apropriado' => round($rows->sum('apropriado'), 2),
            'saldo' => round($rows->sum('saldo'), 2),
        ]];
    }

    /** @return array<int, array{cliente: string, reserva_data: ?string, reserva_numero: string, pago: float}> reserva => dados */
    private function paidByBooking(string $start, string $end): array
    {
        $armador = DB::table('fin_payment_order_items as i')
            ->join('fin_payment_orders as o', 'o.id', '=', 'i.ref_payment_order')
            ->join('fin_expenses_values as fev', 'fev.id', '=', 'i.ref_expense')
            ->where('o.status', 1)
            ->whereBetween('o.payment_date', [$start, $end])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('fin_payment_order_items_disabled as d')
                ->whereColumn('d.ref_fin_payment_order', 'i.ref_payment_order')
                ->whereColumn('d.ref_expense', 'i.ref_expense')
                ->where('d.status', 0))
            ->select('fev.ref_embarque', 'i.item_value');

        $outros = DB::table('ordem_pagamento_item as i')
            ->join('ordem_pagamento as o', 'o.ordem_pagamento_id', '=', 'i.ordem_pagamento_id')
            ->join('fin_expenses_values as fev', 'fev.id', '=', 'i.ref_expense')
            ->where('o.status', 1)
            ->whereBetween('o.payment_date', [$start, $end])
            ->select('fev.ref_embarque', 'i.item_value');

        $totals = [];

        foreach ($armador->unionAll($outros)->get() as $row) {
            $totals[$row->ref_embarque] = ($totals[$row->ref_embarque] ?? 0) + (float) $row->item_value;
        }

        $bookings = [];

        foreach (array_chunk(array_keys(array_filter($totals, fn (float $total) => $total > 0.01)), self::CHUNK) as $chunk) {
            foreach (DB::table('embarque as e')->leftJoin('pessoa as p', 'p.pessoa_id', '=', 'e.cliente_id')->whereIn('e.embarque_id', $chunk)->get(['e.embarque_id', 'e.booking', 'e.data_topo', 'p.nome']) as $row) {
                $bookings[(int) $row->embarque_id] = [
                    'cliente' => filled(trim((string) $row->nome)) ? trim($row->nome) : '(sem cliente)',
                    'reserva_data' => NfseInvoice::date($row->data_topo),
                    'reserva_numero' => trim((string) $row->booking),
                    'pago' => $totals[$row->embarque_id],
                ];
            }
        }

        return $bookings;
    }

    /**
     * @param  list<int>  $bookingIds
     * @return list<int> as reservas com ao menos uma IC com saldo na data, na mesma ordem
     */
    private function withOpenCharge(array $bookingIds, string $date): array
    {
        $open = [];

        foreach (array_chunk($bookingIds, self::CHUNK) as $chunk) {
            $charges = ChargeBalance::candidates(DB::table('fin_charges as fc')->whereIn('fc.ref_booking', $chunk), $date)
                ->get(['fc.ref_booking', 'fc.total_value', 'fc.discount', 'fc.received_value', 'fc.payment_date', 'fc.due_date', 'fc.status']);

            foreach ($charges as $charge) {
                if (! isset($open[$charge->ref_booking]) && ChargeBalance::openAt($charge, $date) > 0.01) {
                    $open[$charge->ref_booking] = true;
                }
            }
        }

        return array_values(array_filter($bookingIds, fn (int $id) => isset($open[$id])));
    }

    /**
     * @param  list<int>  $bookingIds
     * @return array<int, float> reserva => apropriado
     */
    private function accruedByBooking(array $bookingIds): array
    {
        $accrued = array_fill_keys($bookingIds, 0.0);

        foreach (array_chunk($bookingIds, self::CHUNK) as $chunk) {
            $covers = DB::table('capa_processo as cp')
                ->join('capa_processo_despesa_item as i', 'i.capa_processo_id', '=', 'cp.capa_process_id')
                ->whereIn('cp.embarque_id', $chunk)
                ->groupBy('cp.embarque_id')
                ->selectRaw('cp.embarque_id, SUM(i.valor) as apropriado')
                ->pluck('apropriado', 'embarque_id');

            foreach ($covers as $id => $value) {
                $accrued[$id] = (float) $value;
            }

            $withoutCover = array_values(array_filter($chunk, fn (int $id) => $accrued[$id] <= 0.01));

            if ($withoutCover !== []) {
                $this->addGridCosts($accrued, $withoutCover);
            }
        }

        return array_map(fn (float $value) => round($value, 2), $accrued);
    }

    /**
     * Sem capa: o custo de compra da grade. Em R$ entra direto; em outra moeda não há valor em R$ na grade, então vale o que já foi
     * pago da despesa (todas as datas).
     *
     * @param  array<int, float>  $accrued
     * @param  list<int>  $bookingIds
     */
    private function addGridCosts(array &$accrued, array $bookingIds): void
    {
        $foreign = [];

        $rows = DB::table('fin_expenses_values as fev')
            ->join('fin_expenses as fe', 'fe.id', '=', 'fev.ref_expense')
            ->join('embarque as e', 'e.embarque_id', '=', 'fev.ref_embarque')
            ->whereIn('fev.ref_embarque', $bookingIds)
            ->whereRaw('COALESCE(fev.buying_rate, 0) > 0')
            ->selectRaw('fev.ref_embarque, fev.id as fev_id, fev.buying_rate, fev.br_min, fev.ref_currency_br, '.BuyingCost::FACTOR_SQL.' as fator')
            ->get();

        foreach ($rows as $row) {
            if ((int) $row->ref_currency_br === 1) {
                $accrued[$row->ref_embarque] += BuyingCost::value($row);
            } else {
                $foreign[$row->fev_id] = $row->ref_embarque;
            }
        }

        foreach (array_chunk(array_keys($foreign), self::CHUNK) as $chunk) {
            foreach ($this->paidByExpense($chunk) as $expenseId => $paid) {
                $accrued[$foreign[$expenseId]] += $paid;
            }
        }
    }

    /**
     * @param  list<int>  $expenseIds
     * @return array<int, float> despesa da reserva => pago
     */
    private function paidByExpense(array $expenseIds): array
    {
        $armador = DB::table('fin_payment_order_items as i')
            ->join('fin_payment_orders as o', 'o.id', '=', 'i.ref_payment_order')
            ->where('o.status', 1)
            ->whereIn('i.ref_expense', $expenseIds)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('fin_payment_order_items_disabled as d')
                ->whereColumn('d.ref_fin_payment_order', 'i.ref_payment_order')
                ->whereColumn('d.ref_expense', 'i.ref_expense')
                ->where('d.status', 0))
            ->select('i.ref_expense', 'i.item_value');

        $outros = DB::table('ordem_pagamento_item as i')
            ->join('ordem_pagamento as o', 'o.ordem_pagamento_id', '=', 'i.ordem_pagamento_id')
            ->where('o.status', 1)
            ->whereIn('i.ref_expense', $expenseIds)
            ->select('i.ref_expense', 'i.item_value');

        $paid = [];

        foreach ($armador->unionAll($outros)->get() as $row) {
            $paid[$row->ref_expense] = ($paid[$row->ref_expense] ?? 0) + (float) $row->item_value;
        }

        return $paid;
    }
}
