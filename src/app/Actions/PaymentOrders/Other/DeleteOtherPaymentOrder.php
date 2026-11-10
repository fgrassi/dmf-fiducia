<?php

namespace App\Actions\PaymentOrders\Other;

use App\Models\OrdemPagamento;
use Illuminate\Support\Facades\DB;

/**
 * deletePagamento (legado, Outros): apaga a ordem com as instruções, os itens delas e os itens, e devolve a reserva à fila
 * de pagamento de outros (ctr_pgto_outros = 1). Como no legado, os vínculos de parte das instruções ficam órfãos, não há
 * e-mail e vale para qualquer status. Diverge do legado de propósito (decidido em 10/11/2026): grava a exclusão no
 * rastreamento da reserva, com a ordem, o status e quem excluiu.
 */
class DeleteOtherPaymentOrder
{
    public function __invoke(OrdemPagamento $order, int $userId): void
    {
        DB::transaction(function () use ($order, $userId) {
            $instructions = DB::table('instrucao_pagamento_outros')->where('ordem_pagamento_id', $order->getKey());
            DB::table('instrucao_pagamento_item_outros')->whereIn('instrucao_pagamento_outros_id', (clone $instructions)->select('instrucao_pagamento_outros_id'))->delete();
            $instructions->delete();
            $order->items()->delete();
            DB::table('embarque')->where('embarque_id', $order->ref_embarque)->update(['ctr_pgto_outros' => 1]);
            DB::table('rastreamento')->insert([
                'assunto' => 'Ordem de Pagamento Excluída',
                'evento' => 'd',
                'historico' => "<p>Ordem de pagamento de outros nº {$order->getKey()} excluída (status {$order->status}).</p>",
                'ref_embarque' => $order->ref_embarque,
                'created_at' => now()->toDateString(),
                'created_time' => now()->format('H:i:s'),
                'created_by' => $userId,
            ]);
            $order->delete();
        });
    }
}
