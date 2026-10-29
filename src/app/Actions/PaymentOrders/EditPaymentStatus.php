<?php

namespace App\Actions\PaymentOrders;

use App\Models\FinPaymentOrder;
use DomainException;

/** editStatusPagamento (legado): troca o status da ordem (0 aberta, 1 paga, 2 cancelada); sem histórico, como lá. */
class EditPaymentStatus
{
    /** @throws DomainException */
    public function __invoke(FinPaymentOrder $order, int $status): FinPaymentOrder
    {
        if (! array_key_exists($status, FinPaymentOrder::STATUSES)) {
            throw new DomainException('Status inválido.');
        }

        $order->forceFill(['status' => $status])->save();

        return $order;
    }
}
