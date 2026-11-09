<?php

namespace App\Actions\Financeiro;

use App\Actions\Booking\SaveExpense;
use App\Models\FinExpensesValues;
use App\Models\OrdemPagamento;
use App\Models\OrdemPagamentoItem;
use App\Models\TaBaixa;
use App\Services\LegacyMath;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * relatorioTa/darBaixa (legado): lança a baixa de TA (pagamento parcial ou total da despesa TA da reserva, em moeda
 * estrangeira) e a reflete na ordem de pagamento da reserva — usa o item mais novo da despesa ou cria a ordem. Reserva com
 * problema é pulada e relatada; se nenhuma passar, nada é gravado e a recusa sai como DomainException.
 */
class GiveTaPayment
{
    /**
     * @param  array<int, float|string>  $payments  valor pago (R$) por reserva (embarque_id)
     * @return array{processed: int, errors: list<string>}
     */
    public function __invoke(array $payments, float $rate, ?int $userId): array
    {
        if ($payments === []) {
            throw new DomainException('Selecione ao menos um item.');
        }

        if ($rate <= 0) {
            throw new DomainException('Informe uma taxa de câmbio válida.');
        }

        $errors = [];
        $processed = 0;

        DB::transaction(function () use ($payments, $rate, $userId, &$errors, &$processed) {
            foreach ($payments as $bookingId => $paid) {
                try {
                    $this->give((int) $bookingId, round((float) $paid, 2), $rate, $userId);
                    $processed++;
                } catch (DomainException $e) {
                    $errors[] = $e->getMessage();
                }
            }

            if ($processed === 0) {
                throw new DomainException($errors === [] ? 'Não foi possível dar baixa nos itens selecionados.' : implode("\n", $errors));
            }
        });

        return ['processed' => $processed, 'errors' => $errors];
    }

    private function give(int $bookingId, float $paid, float $rate, ?int $userId): void
    {
        $booking = DB::table('embarque')->where('embarque_id', $bookingId)->value('booking');
        $label = "Booking {$booking}";

        if ($booking === null) {
            throw new DomainException("Booking {$bookingId}: embarque não encontrado.");
        }

        $expense = FinExpensesValues::query()
            ->where('ref_embarque', $bookingId)
            ->where('ref_expense', SaveExpense::HIDDEN_FLAGS_EXPENSE)
            ->where('ref_due_to', TaReport::DUE_TO_DMF)
            ->orderByDesc('id')
            ->first() ?? throw new DomainException("{$label}: despesa TA não encontrada.");

        if ($paid <= 0) {
            throw new DomainException("{$label}: informe um valor pago maior que zero.");
        }

        $quantity = (int) DB::table('mercadoria')->where('embarque_id', $bookingId)->sum('quantidade');
        $total = (float) $expense->buying_rate * $quantity;
        $balance = round($total - (float) TaBaixa::where('fin_expenses_values_id', $expense->getKey())->sum('valor_ta'), 2);
        $taValue = LegacyMath::round($paid / $rate, 2);

        if ($taValue - $balance > 0.02) {
            throw new DomainException("{$label}: o valor pago excede o saldo.");
        }

        $item = $this->item($bookingId, $expense, $rate, $paid, $quantity);

        TaBaixa::forceCreate([
            'fin_expenses_values_id' => $expense->getKey(),
            'embarque_id' => $bookingId,
            'ordem_pagamento_id' => $item->ordem_pagamento_id,
            'ordem_pagamento_item_id' => $item->getKey(),
            'valor_ta' => min($taValue, $balance),
            'taxa' => $rate,
            'valor_pago' => $paid,
            'data_pagamento' => now()->toDateString(),
            'usuario_id' => $userId,
        ]);

        $this->sync($bookingId, $expense, $item, $rate, $total);
    }

    /** Itens da despesa TA nas ordens da reserva que não foram canceladas (status 2), do mais antigo ao mais novo. */
    private function items(int $bookingId): Collection
    {
        return OrdemPagamentoItem::query()
            ->join('ordem_pagamento as op', 'op.ordem_pagamento_id', '=', 'ordem_pagamento_item.ordem_pagamento_id')
            ->join('fin_expenses_values as fev', 'fev.id', '=', 'ordem_pagamento_item.ref_expense')
            ->where('op.ref_embarque', $bookingId)
            ->where('fev.ref_expense', SaveExpense::HIDDEN_FLAGS_EXPENSE)
            ->where('fev.ref_due_to', TaReport::DUE_TO_DMF)
            ->where(fn ($query) => $query->whereNull('op.status')->orWhere('op.status', '<>', 2))
            ->orderBy('ordem_pagamento_item.ordem_pagamento_item_id')
            ->get(['ordem_pagamento_item.*']);
    }

    /** obterOuCriarItemOpTa: reativa o item mais novo da despesa ou cria a ordem (tipo 2 se a reserva já teve ordem) com o item. */
    private function item(int $bookingId, FinExpensesValues $expense, float $rate, float $paid, int $quantity): OrdemPagamentoItem
    {
        if ($item = $this->items($bookingId)->last()) {
            $item->status = 1;
            $item->current_rate_value = $rate;
            if ((float) $item->unitary_value <= 0) {
                $item->unitary_value = (float) $expense->buying_rate;
            }
            $item->save();

            return $item;
        }

        $today = now()->toDateString();
        $order = OrdemPagamento::forceCreate([
            'ref_embarque' => $bookingId,
            'created_at' => $today,
            'ref_type' => OrdemPagamento::where('ref_embarque', $bookingId)->exists() ? 2 : 1,
            'status' => 0,
            'payment_date' => $today,
            'total_value' => $paid,
        ]);

        return OrdemPagamentoItem::forceCreate([
            'ordem_pagamento_id' => $order->getKey(),
            'ref_expense' => $expense->getKey(),
            'unitary_value' => (float) $expense->buying_rate,
            'ref_currency' => $expense->ref_currency_br ?: 2,
            'current_rate_value' => $rate,
            'item_value' => $paid,
            'indice' => max($quantity, 1),
            'status' => 1,
            'created_at' => $today,
        ]);
    }

    /** sincronizarOpTa: o item escolhido recebe a soma das baixas; os demais zeram; as ordens somam os itens e quitam se o saldo zerou. */
    private function sync(int $bookingId, FinExpensesValues $expense, OrdemPagamentoItem $canonical, float $rate, float $total): void
    {
        $sums = TaBaixa::where('fin_expenses_values_id', $expense->getKey())->selectRaw('COALESCE(SUM(valor_ta), 0) as ta, COALESCE(SUM(valor_pago), 0) as paid')->first();
        $settled = round($total - (float) $sums->ta, 2) <= 0.009;

        $canonical->current_rate_value = $rate;
        $canonical->item_value = round((float) $sums->paid, 2);
        $canonical->status = 1;
        $canonical->save();

        $items = $this->items($bookingId);

        $items->where('ordem_pagamento_item_id', '!=', $canonical->getKey())->each(function (OrdemPagamentoItem $item) {
            $item->forceFill(['item_value' => 0, 'status' => 0])->save();
        });

        OrdemPagamento::whereIn('ordem_pagamento_id', $items->pluck('ordem_pagamento_id')->unique())->get()->each(function (OrdemPagamento $order) use ($settled) {
            $order->total_value = (float) $order->items()->where(fn ($query) => $query->whereNull('status')->orWhere('status', '<>', 0))->sum('item_value');
            $order->status = $settled ? 1 : 0;
            $order->payment_date = now()->toDateString();
            $order->save();
        });
    }
}
