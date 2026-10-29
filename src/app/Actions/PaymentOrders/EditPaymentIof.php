<?php

namespace App\Actions\PaymentOrders;

use App\Models\FinPaymentOrder;
use App\Models\InstrucaoPagamento;
use DomainException;

/**
 * Edição do IOF (despesas financeiras) da ordem e de cada instrução (common/editCampo no legado). O IOF da instrução é
 * copiado do da ordem na geração e depois independe dele.
 */
class EditPaymentIof
{
    /** @throws DomainException */
    public function order(FinPaymentOrder $order, float $iof): FinPaymentOrder
    {
        $order->forceFill(['valor_iof' => $this->valid($iof)])->save();

        return $order;
    }

    /** @throws DomainException */
    public function instruction(InstrucaoPagamento $instruction, float $iof): InstrucaoPagamento
    {
        $instruction->forceFill(['valor_iof' => $this->valid($iof)])->save();

        return $instruction;
    }

    private function valid(float $iof): float
    {
        return $iof >= 0 ? $iof : throw new DomainException('O IOF não pode ser negativo.');
    }
}
