<?php

namespace App\Actions\PaymentOrders\Other;

use App\Models\OrdemPagamento;
use Illuminate\Support\Facades\DB;

/**
 * deletePagamento (legado, Outros): apaga a ordem com as instruções, os itens delas e os itens, e devolve a reserva à fila
 * de pagamento de outros (ctr_pgto_outros = 1). Como no legado, os vínculos de parte das instruções ficam órfãos, não há
 * histórico nem e-mail e vale para qualquer status.
 */
class DeleteOtherPaymentOrder
{
    public function __invoke(OrdemPagamento $order): void
    {
        DB::transaction(function () use ($order) {
            $instructions = DB::table('instrucao_pagamento_outros')->where('ordem_pagamento_id', $order->getKey());
            DB::table('instrucao_pagamento_item_outros')->whereIn('instrucao_pagamento_outros_id', (clone $instructions)->select('instrucao_pagamento_outros_id'))->delete();
            $instructions->delete();
            $order->items()->delete();
            DB::table('embarque')->where('embarque_id', $order->ref_embarque)->update(['ctr_pgto_outros' => 1]);
            $order->delete();
        });
    }
}
