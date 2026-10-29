<?php

namespace App\Actions\PaymentOrders;

use App\Models\FinPaymentOrder;
use App\Models\OrdemPagamento;
use DomainException;

/** editStatusPagamento (legado): troca o status da ordem (0 aberta, 1 paga, 2 cancelada); sem histórico, como lá. */
class EditPaymentStatus
{
    /** @throws DomainException */
    public function __invoke(FinPaymentOrder|OrdemPagamento $order, int $status): FinPaymentOrder|OrdemPagamento
    {
        if (! array_key_exists($status, FinPaymentOrder::STATUSES)) {
            throw new DomainException('Status inválido.');
        }

        $order->forceFill(['status' => $status])->save();

        return $order;
    }
}
