<?php

namespace App\Actions\PaymentOrders;

use App\Models\FinCustomPaymentOrderCurrencyRate;

/** listaCustom act=3 (legado): exclui a cotação negociada da ordem. Só vale no próximo recálculo. */
class DeleteOrderCustomRate
{
    public function __invoke(FinCustomPaymentOrderCurrencyRate $rate): void
    {
        $rate->delete();
    }
}
