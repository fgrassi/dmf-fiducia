<?php

namespace App\Actions\PaymentOrders;

use App\Models\FinArmadorBanks;
use App\Models\FinPaymentOrder;
use DomainException;

/**
 * atualizaConta (legado): escolhe a conta do armador que recebe o pagamento; sem conta (0 ou vazio) vale pagamento via
 * boleto. O legado aceitava qualquer id; aqui a conta tem de ser uma conta ativa do armador da ordem.
 */
class SetOrderBank
{
    /** @throws DomainException */
    public function __invoke(FinPaymentOrder $order, ?int $bankId): FinPaymentOrder
    {
        if ($bankId && ! FinArmadorBanks::active()->where('ref_armador', $order->ref_armador)->whereKey($bankId)->exists()) {
            throw new DomainException('A conta não pertence ao armador da ordem ou está desativada.');
        }

        $order->forceFill(['fin_armador_bank_id' => $bankId ?: null])->save();

        return $order;
    }
}
