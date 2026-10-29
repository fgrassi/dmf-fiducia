<?php

namespace App\Actions\PaymentOrders;

use App\Models\FinPaymentOrder;

/** atualizaCi (legado): código de identificação do pagamento, quando o armador tem um específico. Sem histórico. */
class EditOrderCode
{
    public function __invoke(FinPaymentOrder $order, ?string $code): FinPaymentOrder
    {
        $order->forceFill(['codigo_identificacao' => $code])->save();

        return $order;
    }
}
