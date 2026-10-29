<?php

namespace App\Actions\PaymentOrders;

use App\Models\FinPaymentOrder;
use App\Models\OrdemPagamento;
use Carbon\CarbonImmutable;
use DomainException;
use Throwable;

/** editDataPagamento (legado): grava a data de pagamento da ordem; sem histórico, como lá. */
class EditPaymentDate
{
    /** @throws DomainException */
    public function __invoke(FinPaymentOrder|OrdemPagamento $order, string $date): FinPaymentOrder|OrdemPagamento
    {
        try {
            $paid = CarbonImmutable::parse($date)->toDateString();
        } catch (Throwable) {
            throw new DomainException('Data de pagamento inválida.');
        }

        $order->forceFill(['payment_date' => $paid])->save();

        return $order;
    }
}
