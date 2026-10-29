<?php

namespace App\Actions\PaymentOrders;

use App\Models\FinPaymentOrder;
use Illuminate\Support\Facades\DB;

/**
 * deletePagamento (legado): apaga a ordem com itens, controle de itens desligados e instruções, e devolve a reserva à
 * fila de pagamento (ctr_pgto = 1). Como no legado, as cotações negociadas ficam na tabela e não há histórico nem e-mail.
 */
class DeletePaymentOrder
{
    public function __invoke(FinPaymentOrder $order): void
    {
        DB::transaction(function () use ($order) {
            DB::table('instrucao_pagamento_item')->whereIn('fin_payment_order_items_id', $order->items()->select('id'))->delete();
            $order->items()->delete();
            DB::table('fin_payment_order_items_disabled')->where('ref_fin_payment_order', $order->getKey())->delete();
            DB::table('instrucao_pagamento')->where('fin_payment_order_id', $order->getKey())->delete();
            DB::table('embarque')->where('embarque_id', $order->ref_embarque)->update(['ctr_pgto' => 1]);
            $order->delete();
        });
    }
}
