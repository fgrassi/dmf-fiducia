<?php

namespace App\Actions\PaymentOrders;

use App\Models\FinPaymentOrderItem;
use Illuminate\Support\Facades\DB;

/**
 * atualizaValorUnitario, edição da taxa (common/editCampo) e atualizaItem (legado): altera o valor unitário ou a taxa do
 * item e refaz o valor em reais com a regra do pagamento. Sem histórico, como no legado.
 */
class EditOrderItem
{
    public function __construct(private OrderItemValue $value) {}

    public function unitValue(FinPaymentOrderItem $item, float $unit): FinPaymentOrderItem
    {
        $item->unitary_value = $unit;

        return $this->refresh($item);
    }

    public function rate(FinPaymentOrderItem $item, float $rate): FinPaymentOrderItem
    {
        $item->current_rate_value = $rate;

        return $this->refresh($item);
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
