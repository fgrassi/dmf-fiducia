<?php

namespace App\Actions\PaymentOrders;

use App\Models\FinPaymentOrder;
use App\Models\InstrucaoPagamento;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * geraInstrucaoPagamento (legado): grava uma instrução com todos os itens da ordem cuja despesa não está desligada, com o
 * valor de cada item e o IOF da ordem. O legado não impede gerar de novo: itens já instruídos entram outra vez.
 */
class GeneratePaymentInstruction
{
    private const DISABLED = 0;

    /** @throws DomainException sem itens ligados */
    public function __invoke(FinPaymentOrder $order): InstrucaoPagamento
    {
        $items = $order->items()
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('fin_payment_order_items_disabled as d')
                ->whereColumn('d.ref_fin_payment_order', 'fin_payment_order_items.ref_payment_order')
                ->whereColumn('d.ref_expense', 'fin_payment_order_items.ref_expense')
                ->where('d.status', self::DISABLED))
            ->orderBy('id')
            ->get();

        if ($items->isEmpty()) {
            throw new DomainException('A ordem não tem despesas ligadas para gerar a instrução.');
        }

        return DB::transaction(function () use ($order, $items) {
            $instruction = new InstrucaoPagamento;
            $instruction->forceFill([
                'created_at' => now(),
                'fin_payment_order_id' => $order->getKey(),
                'valor_iof' => $order->valor_iof,
            ])->save();

            foreach ($items as $item) {
                $instruction->items()->forceCreate(['fin_payment_order_items_id' => $item->id, 'valor' => $item->item_value]);
            }

            return $instruction;
        });
    }
}
