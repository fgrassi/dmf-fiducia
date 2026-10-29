<?php

namespace App\Actions\PaymentOrders;

use App\Models\FinPaymentOrder;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * habilitaDesabilita (legado): liga ou desliga uma despesa da ordem (fin_payment_order_items_disabled; sem linha = ligada).
 * O legado alterna uma vez por item da despesa, então com um número par de itens (BLs) o clique não mudava nada;
 * aqui alterna uma vez só (divergência registrada em ordens-de-pagamento.md).
 */
class ToggleOrderExpense
{
    /**
     * @return bool a despesa ficou ligada
     *
     * @throws DomainException
     */
    public function __invoke(FinPaymentOrder $order, int $expenseValueId, int $userId): bool
    {
        if (! $order->items()->where('ref_expense', $expenseValueId)->exists()) {
            throw new DomainException('A despesa não faz parte desta ordem.');
        }

        $control = DB::table('fin_payment_order_items_disabled')
            ->where('ref_fin_payment_order', $order->getKey())->where('ref_expense', $expenseValueId);

        if ($row = $control->first()) {
            $enabled = (int) $row->status === 0;
            $control->update(['status' => $enabled ? 1 : 0]);

            return $enabled;
        }

        DB::table('fin_payment_order_items_disabled')->insert([
            'ref_fin_payment_order' => $order->getKey(),
            'ref_expense' => $expenseValueId,
            'created_by' => $userId,
            'created_at' => now()->toDateString(),
            'status' => 0,
        ]);

        return false;
    }
}
