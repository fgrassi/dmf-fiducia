<?php

namespace App\Actions\Financeiro;

use App\Actions\Booking\SaveExpense;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * relatorioTa/loadValues (legado), "Relatório TA": uma linha por reserva com a despesa TA (1916, due to DMF) — total em moeda
 * estrangeira, quanto já foi baixado (`ta_baixa`), saldo, se o cliente pagou o frete e o valor sugerido para a próxima baixa.
 * Reserva cancelada não entra. Se houver mais de uma despesa TA na reserva, vale a última (a mesma que a baixa usa).
 */
class TaReport
{
    /** fin_expenses_values_id.ref_due_to da DMF (FinExpensesValues::DUE_TO_DMF_ID do legado). */
    public const DUE_TO_DMF = 7;

    /** regra_despesa_cobranca que marca a cobrança do frete (getPaymentData do legado). */
    private const FREIGHT_RULE = 4;

    /**
     * @param  array<string, mixed>  $filters  shipper_id, booking, fatura, porto_origem_id, porto_destino_id, etd_ini, etd_fim,
     *                                         saida_efetiva_ini, saida_efetiva_fim, embarques_confirmados, pago (s|p|n), recebido (s|n)
     * @return Collection<int, array<string, mixed>> as linhas, na ordem ETD e booking
     */
    public function __invoke(array $filters = []): Collection
    {
        $records = $this->records($filters);
        $ids = $records->pluck('embarque_id')->all();
        $expenseIds = $records->pluck('expense_value_id')->all();

        $quantities = DB::table('mercadoria')->whereIn('embarque_id', $ids)->groupBy('embarque_id')
            ->selectRaw('embarque_id, SUM(quantidade) as qtd')->pluck('qtd', 'embarque_id');
        $received = $this->received($ids);
        $payments = DB::table('ta_baixa')->whereIn('fin_expenses_values_id', $expenseIds)->orderBy('ta_baixa_id')->get()->groupBy('fin_expenses_values_id');
        $orders = $this->orderItems($ids);

        return $records
            ->map(fn (object $record) => $this->row($record, (int) ($quantities[$record->embarque_id] ?? 0), $received[$record->embarque_id] ?? 'Não', $payments[$record->expense_value_id] ?? collect(), $orders[$record->embarque_id] ?? collect()))
            ->filter(fn (array $row) => match ($filters['pago'] ?? null) {
                's' => $row['embarque_pago'] === 'Sim',
                'p' => $row['embarque_pago'] === 'Parcial',
                'n' => $row['embarque_pago'] === 'Não',
                default => true,
            })
            ->filter(fn (array $row) => match ($filters['recebido'] ?? null) {
                's' => $row['recebido'] === 'Sim',
                'n' => $row['recebido'] === 'Não',
                default => true,
            })
            ->values();
    }

    /** @return Collection<int, object> */
    private function records(array $filters): Collection
    {
        return DB::table('embarque as e')
            ->join('fin_expenses_values as fev', function ($join) {
                $join->on('fev.ref_embarque', '=', 'e.embarque_id')
                    ->where('fev.ref_expense', SaveExpense::HIDDEN_FLAGS_EXPENSE)
                    ->where('fev.ref_due_to', self::DUE_TO_DMF)
                    ->whereRaw('fev.id = (select max(f2.id) from fin_expenses_values f2 where f2.ref_embarque = e.embarque_id and f2.ref_expense = fev.ref_expense and f2.ref_due_to = fev.ref_due_to)');
            })
            ->leftJoin('pessoa as pe', 'pe.pessoa_id', '=', 'e.pessoa_id_cliente')
            ->leftJoin('porto as po', 'po.porto_id', '=', 'e.porto_origem_id')
            ->leftJoin('porto as pd', 'pd.porto_id', '=', 'e.porto_destino_id')
            ->where(fn ($query) => $query->where('e.finalizado', '<>', 'c')->orWhereNull('e.finalizado'))
            ->when($filters['shipper_id'] ?? null, fn ($query, $id) => $query->where('e.pessoa_id_cliente', $id))
            ->when(filled($booking = trim((string) ($filters['booking'] ?? ''))), fn ($query) => $query->where('e.booking', 'like', "%{$booking}%"))
            ->when(filled($invoice = trim((string) ($filters['fatura'] ?? ''))), fn ($query) => $query->where('e.invoice', 'like', "%{$invoice}%"))
            ->when($filters['porto_origem_id'] ?? null, fn ($query, $id) => $query->where('e.porto_origem_id', $id))
            ->when($filters['porto_destino_id'] ?? null, fn ($query, $id) => $query->where('e.porto_destino_id', $id))
            ->when($filters['etd_ini'] ?? null, fn ($query, $date) => $query->where('e.saida_prevista', '>=', $date))
            ->when($filters['etd_fim'] ?? null, fn ($query, $date) => $query->where('e.saida_prevista', '<=', $date))
            ->when($filters['saida_efetiva_ini'] ?? null, fn ($query, $date) => $query->where('e.saida_efetiva', '>=', $date))
            ->when($filters['saida_efetiva_fim'] ?? null, fn ($query, $date) => $query->where('e.saida_efetiva', '<=', $date))
            ->when($filters['embarques_confirmados'] ?? false, fn ($query) => $query->whereNotNull('e.saida_efetiva'))
            ->orderBy('e.saida_prevista')
            ->orderBy('e.booking')
            ->get(['e.embarque_id', 'pe.nome as shipper', 'e.booking', 'e.invoice as fatura', 'po.nome as origem', 'pd.nome as destino', 'e.saida_prevista as etd', 'e.saida_efetiva', 'fev.id as expense_value_id', 'fev.buying_rate as valor_unitario']);
    }

    /** "Sim" quando a reserva tem cobrança de frete e nenhuma está em aberto. @return array<int, string> */
    private function received(array $bookingIds): array
    {
        return DB::table('fin_charges as fc')
            ->whereIn('fc.ref_booking', $bookingIds)
            ->whereExists(fn ($query) => $query->from('fin_charges_items as fci')
                ->join('fin_expenses_values as fev', 'fev.id', '=', 'fci.ref_expense')
                ->join('regra_despesa_cobranca_item as rd', fn ($join) => $join->on('rd.despesa_id', '=', 'fev.ref_expense')->where('rd.regra_despesa_cobranca_id', self::FREIGHT_RULE))
                ->whereColumn('fci.ref_charge', 'fc.id')
                ->select(DB::raw(1)))
            ->groupBy('fc.ref_booking')
            ->selectRaw('fc.ref_booking, SUM(CASE WHEN fc.status = 0 THEN 1 ELSE 0 END) as open')
            ->get()
            ->mapWithKeys(fn (object $row) => [$row->ref_booking => (int) $row->open === 0 ? 'Sim' : 'Não'])
            ->all();
    }

    /** Itens da despesa TA nas ordens de cada reserva (findItensOpTa do legado). @return Collection<int, Collection<int, object>> */
    private function orderItems(array $bookingIds): Collection
    {
        return DB::table('ordem_pagamento_item as i')
            ->join('ordem_pagamento as op', 'op.ordem_pagamento_id', '=', 'i.ordem_pagamento_id')
            ->join('fin_expenses_values as fev', 'fev.id', '=', 'i.ref_expense')
            ->whereIn('op.ref_embarque', $bookingIds)
            ->where('fev.ref_expense', SaveExpense::HIDDEN_FLAGS_EXPENSE)
            ->where('fev.ref_due_to', self::DUE_TO_DMF)
            ->orderBy('i.ordem_pagamento_item_id')
            ->get(['op.ref_embarque', 'i.status', 'i.item_value', 'i.current_rate_value', 'op.status as order_status'])
            ->groupBy('ref_embarque');
    }

    /**
     * @param  Collection<int, object>  $payments  as baixas da despesa, da mais antiga para a mais nova
     * @param  Collection<int, object>  $orderItems
     * @return array<string, mixed>
     */
    private function row(object $record, int $quantity, string $received, Collection $payments, Collection $orderItems): array
    {
        $total = (float) $record->valor_unitario * $quantity;
        $paid = (float) $payments->sum('valor_pago');
        $balance = max(round($total - (float) $payments->sum('valor_ta'), 2), 0);
        $lastRate = (float) ($payments->last()->taxa ?? 0);
        $rate = $lastRate > 0 ? $lastRate : null;
        $open = $orderItems->filter(fn (object $item) => ($item->status === null || (int) $item->status !== 0) && (int) $item->order_status !== 2);
        $orderRate = $open->map(fn (object $item) => (float) $item->current_rate_value)->filter(fn (float $value) => $value > 0)->last();
        $suggested = $rate ?? $orderRate;
        $orderValue = round((float) $open->sum('item_value'), 2);

        return [
            'embarque_id' => (int) $record->embarque_id,
            'expense_value_id' => (int) $record->expense_value_id,
            'shipper' => $record->shipper,
            'booking' => $record->booking,
            'fatura' => $record->fatura,
            'origem' => $record->origem,
            'destino' => $record->destino,
            'etd' => $record->etd,
            'saida_efetiva' => $record->saida_efetiva,
            'qtd_container' => $quantity,
            'total_ta' => $total,
            'recebido' => $received,
            'embarque_pago' => match (true) {
                $payments->isEmpty() => 'Não',
                $balance > 0.009 => 'Parcial',
                default => 'Sim',
            },
            'taxa_pagamento' => $rate,
            'taxa_sugerida' => $suggested,
            'valor_pago' => $paid,
            'saldo' => $balance,
            'valor_ta_rs' => $paid,
            'has_op' => $open->isNotEmpty(),
            'prefill_valor_pago' => match (true) {
                $payments->isEmpty() && $open->isNotEmpty() && $orderValue > 0 => $orderValue,
                (bool) $suggested => round($balance * $suggested, 2),
                default => 0,
            },
            'qtd_baixas' => $payments->count(),
            'ordem_pagamento_id' => $payments->max('ordem_pagamento_id') ?: null,
        ];
    }
}
