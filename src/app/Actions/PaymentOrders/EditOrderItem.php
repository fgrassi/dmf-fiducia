<?php

namespace App\Actions\PaymentOrders;

use App\Actions\Booking\BookingLog;
use App\Models\FinPaymentOrderItem;
use Illuminate\Support\Facades\DB;

/**
 * atualizaValorUnitario, edição da taxa (common/editCampo) e atualizaItem (legado): altera o valor unitário ou a taxa do
 * item e refaz o valor em reais com a regra do pagamento. Como no legado, só a troca da taxa fica em
 * rastreabilidade_alteracao.
 */
class EditOrderItem
{
    public function __construct(private OrderItemValue $value, private BookingLog $log) {}

    public function unitValue(FinPaymentOrderItem $item, float $unit): FinPaymentOrderItem
    {
        $item->unitary_value = $unit;

        return $this->refresh($item);
    }

    public function rate(FinPaymentOrderItem $item, float $rate, int $userId): FinPaymentOrderItem
    {
        $format = fn ($value) => number_format((float) $value, 4, '.', '');
        $old = $item->current_rate_value;
        $item->current_rate_value = $rate;
        $this->refresh($item);

        $this->log->fieldChange($item->getTable(), 'current_rate_value', (int) $item->getKey(), $old === null ? null : $format($old), $format($rate), $userId);

        return $item;
    }

    private function refresh(FinPaymentOrderItem $item): FinPaymentOrderItem
    {
        $expense = DB::table('fin_expenses_values')->where('id', $item->ref_expense)->first();
        $mbl = DB::table('fin_payment_orders')->where('id', $item->ref_payment_order)->value('mbl');

        $item->item_value = $this->value->debit($expense, (float) $item->current_rate_value, (float) $item->unitary_value, $mbl);
        $item->save();

        return $item;
    }
}
