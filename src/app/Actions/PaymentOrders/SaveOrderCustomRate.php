<?php

namespace App\Actions\PaymentOrders;

use App\Models\FinCustomPaymentOrderCurrencyRate;
use App\Models\FinPaymentOrder;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * listaCustom act=2 (legado): cadastra a cotação negociada da ordem, para uma moeda ou para uma despesa da reserva.
 * Só vale no próximo recálculo. O legado não validava nada; aqui exige moeda, valor maior que zero e despesa da mesma reserva.
 */
class SaveOrderCustomRate
{
    /** @throws DomainException */
    public function __invoke(FinPaymentOrder $order, int $currency, ?int $expenseValueId, float $rate, ?string $note, int $userId): FinCustomPaymentOrderCurrencyRate
    {
        if ($rate <= 0) {
            throw new DomainException('Informe um valor negociado maior que zero.');
        }

        if ($expenseValueId && ! DB::table('fin_expenses_values')->where('id', $expenseValueId)->where('ref_embarque', $order->ref_embarque)->exists()) {
            throw new DomainException('A despesa não pertence à reserva da ordem.');
        }

        $custom = new FinCustomPaymentOrderCurrencyRate;
        $custom->forceFill([
            'ref_payment_order' => $order->getKey(),
            'ref_expense' => $expenseValueId,
            'ref_currency' => $currency,
            'note' => $note,
            'custom_rate' => $rate,
            'created_at' => now()->toDateString(),
            'created_by' => $userId,
        ])->save();

        return $custom;
    }
}
