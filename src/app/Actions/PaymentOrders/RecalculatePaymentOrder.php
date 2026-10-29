<?php

namespace App\Actions\PaymentOrders;

use App\Models\CmnCurrency;
use App\Models\Embarque;
use App\Models\FinPaymentOrder;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * recalculaPagamento (legado): refaz os itens da ordem aberta com as despesas e cotações de hoje. Pula os itens já em
 * instrução de pagamento. Como no legado, grava updated_at antes de conferir as cotações.
 */
class RecalculatePaymentOrder
{
    private const OPEN = 0;

    private const REAL = 1;

    public function __construct(private OrderItemValue $value) {}

    /**
     * @return float novo total da ordem
     *
     * @throws DomainException
     */
    public function __invoke(FinPaymentOrder $order): float
    {
        if ((int) $order->status !== self::OPEN) {
            throw new DomainException('Não é possível recalcular pagamentos pagas.');
        }

        $order->forceFill(['updated_at' => now()->toDateString()])->save();
        $booking = Embarque::findOrFail($order->ref_embarque);

        $this->ensureQuotes($booking, $order);

        return DB::transaction(function () use ($booking, $order) {
            $items = $order->items()
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('instrucao_pagamento_item as ipi')->whereColumn('ipi.fin_payment_order_items_id', 'fin_payment_order_items.id'))
                ->get();

            foreach ($items as $item) {
                $expense = DB::table('fin_expenses_values')->where('id', $item->ref_expense)->first();
                $rate = $this->value->quote((int) $expense->ref_currency_br, $booking->paymentCarrierId(), $booking->impor_expor, $order->getKey(), (int) $expense->id);

                $item->forceFill([
                    'unitary_value' => $expense->buying_rate,
                    'ref_currency' => $expense->ref_currency_br,
                    'current_rate_value' => $rate,
                    'item_value' => $this->value->debit($expense, $rate, (float) $expense->buying_rate, $order->mbl),
                ])->save();
            }

            $total = (float) $order->items()->sum('item_value');
            $order->forceFill(['total_value' => $total])->save();

            return $total;
        });
    }

    /** verificaCotacoes: toda moeda estrangeira da ordem precisa de cotação negociada ou do dia do armador. */
    private function ensureQuotes(Embarque $booking, FinPaymentOrder $order): void
    {
        if (! in_array($booking->impor_expor, ['i', 'e'], true)) {
            return;
        }

        $missing = [];
        $carrier = $booking->paymentCarrierId();

        foreach ($order->items()->where('ref_currency', '<>', self::REAL)->distinct()->pluck('ref_currency') as $currency) {
            $negotiated = DB::table('fin_custom_payment_order_currency_rate')->where('ref_currency', $currency)->where('ref_payment_order', $order->getKey())->exists();
            $today = DB::table('fin_currencies_rates_armador_payment_values as v')
                ->join('fin_currencies_rates_armador_payment as p', 'p.id', '=', 'v.ref_currency_rate_armador')
                ->where('v.rate_date', now()->toDateString())->where('p.ref_currency', $currency)->where('p.ref_armador', $carrier)->where('p.type', $booking->impor_expor)
                ->exists();

            if (! $negotiated && ! $today) {
                $moeda = CmnCurrency::find($currency);
                $missing[] = "Reserva: {$booking->booking}. Moeda: {$moeda?->name} ({$moeda?->symbol}) (".($booking->impor_expor === 'i' ? 'Importação' : 'Exportação').'). Armador: '.DB::table('armador')->where('armador_id', $carrier)->value('nome');
            }
        }

        if ($missing !== []) {
            throw new DomainException('Não foi possível atualizar o pagamento. Taxas desatualizadas. '.implode(' | ', $missing));
        }
    }
}
