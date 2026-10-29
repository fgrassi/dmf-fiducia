<?php

namespace App\Actions\PaymentOrders\Other;

use App\Actions\Booking\SaveExpense;
use App\Models\FinExpensesValues;
use App\Models\OrdemPagamento;
use App\Models\TaBaixa;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * editarBaixaTa, excluirBaixaTa e TaBaixa::sincronizarOp (legado): altera ou exclui uma baixa de TA da ordem e
 * reflete a soma das baixas no item da despesa TA e no total/status da ordem (paga quando o saldo zera).
 */
class EditTaPayment
{
    /** ordemTemDespesaTa: a ordem tem item da despesa TA (tipo 1916). */
    public function orderHasTa(OrdemPagamento $order): bool
    {
        return DB::table('ordem_pagamento_item as i')
            ->join('fin_expenses_values as v', 'v.id', '=', 'i.ref_expense')
            ->where('i.ordem_pagamento_id', $order->getKey())
            ->where('v.ref_expense', SaveExpense::HIDDEN_FLAGS_EXPENSE)
            ->exists();
    }

    public function update(OrdemPagamento $order, TaBaixa $payment, float $rate, float $paid, string $date, ?int $userId): TaBaixa
    {
        $this->guard($order, $payment, 'alterar');

        if ($rate <= 0) {
            throw new DomainException('Informe uma taxa válida.');
        }

        if ($paid <= 0) {
            throw new DomainException('Informe um valor pago maior que zero.');
        }

        $expense = FinExpensesValues::find($payment->fin_expenses_values_id) ?? throw new DomainException('Despesa TA não encontrada.');
        $balance = round($this->total($expense) - (float) TaBaixa::where('fin_expenses_values_id', $expense->getKey())->whereKeyNot($payment->getKey())->sum('valor_ta'), 2);
        $taValue = round($paid / $rate, 2);

        if ($taValue - $balance > 0.02) {
            throw new DomainException('O valor pago excede o saldo disponível.');
        }

        DB::transaction(function () use ($payment, $rate, $paid, $date, $userId, $taValue, $balance) {
            $payment->forceFill([
                'taxa' => $rate,
                'valor_pago' => round($paid, 2),
                'valor_ta' => min($taValue, $balance),
                'data_pagamento' => $date,
                'updated_by' => $userId,
            ])->save();

            $this->sync($payment->fin_expenses_values_id, $payment->ordem_pagamento_id, $payment->ordem_pagamento_item_id, $rate);
        });

        return $payment;
    }

    public function delete(OrdemPagamento $order, TaBaixa $payment): void
    {
        $this->guard($order, $payment, 'excluir');

        DB::transaction(function () use ($payment) {
            $payment->delete();
            $this->sync($payment->fin_expenses_values_id, $payment->ordem_pagamento_id, $payment->ordem_pagamento_item_id);
        });
    }

    private function guard(OrdemPagamento $order, TaBaixa $payment, string $verb): void
    {
        if ((int) $payment->ordem_pagamento_id !== (int) $order->getKey()) {
            throw new DomainException('Baixa não pertence a esta ordem de pagamento.');
        }

        if (! $this->orderHasTa($order)) {
            throw new DomainException('Esta ordem de pagamento não é de TA.');
        }

        if (! $payment->canChange()) {
            throw new DomainException("Baixa já vinculada à capa. Não é possível {$verb}.");
        }
    }

    /** Valor total da TA: custo unitário × quantidade de mercadorias da reserva. */
    private function total(FinExpensesValues $expense): float
    {
        return round((float) $expense->buying_rate * (int) DB::table('mercadoria')->where('embarque_id', $expense->ref_embarque)->sum('quantidade'), 2);
    }

    /**
     * sincronizarOp: o item "canônico" da despesa (o da baixa, senão o último) recebe o valor pago e a taxa; os demais
     * itens da mesma despesa zeram. Ordem cancelada não muda.
     */
    private function sync(int $expenseId, int $orderId, ?int $itemId, ?float $rate = null): void
    {
        $expense = FinExpensesValues::find($expenseId);
        $order = OrdemPagamento::find($orderId);

        if (! $expense || ! $order) {
            return;
        }

        $sums = TaBaixa::where('fin_expenses_values_id', $expenseId)->selectRaw('COALESCE(SUM(valor_ta), 0) as ta, COALESCE(SUM(valor_pago), 0) as paid')->first();
        $paid = round((float) $sums->paid, 2);
        $settled = round($this->total($expense) - (float) $sums->ta, 2) <= 0.009;
        $last = TaBaixa::where('fin_expenses_values_id', $expenseId)->orderByDesc('ta_baixa_id')->first();

        if (($rate ?? 0) <= 0) {
            $rate = $last ? (float) $last->taxa : null;
        }

        $itemId ??= $last?->ordem_pagamento_item_id;

        if ((int) $order->status === 2) {
            return;
        }

        $items = $order->items()->where('ref_expense', $expenseId)->orderBy('ordem_pagamento_item_id')->get();
        $canonical = $items->firstWhere('ordem_pagamento_item_id', $itemId) ?? $items->last();

        if ($canonical) {
            if ($rate > 0) {
                $canonical->current_rate_value = $rate;
            }

            $canonical->item_value = $paid;
            $canonical->status = $paid > 0 ? 1 : 0;
            $canonical->save();

            $items->where('ordem_pagamento_item_id', '!=', $canonical->getKey())->each->forceFill(['item_value' => 0, 'status' => 0])->each->save();
        }

        $order->total_value = (float) $order->items()->where(fn ($q) => $q->whereNull('status')->orWhere('status', '<>', 0))->sum('item_value');
        $order->status = $settled ? 1 : 0;
        $order->save();
    }
}
