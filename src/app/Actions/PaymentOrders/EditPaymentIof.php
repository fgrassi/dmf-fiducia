<?php

namespace App\Actions\PaymentOrders;

use App\Models\FinPaymentOrder;
use App\Models\InstrucaoPagamento;
use App\Models\InstrucaoPagamentoOutros;
use App\Models\OrdemPagamento;
use DomainException;

/**
 * Edição do IOF (despesas financeiras) da ordem e de cada instrução (common/editCampo no legado). O IOF da instrução é
 * copiado do da ordem na geração e depois independe dele.
 */
class EditPaymentIof
{
    /** @throws DomainException */
    public function order(FinPaymentOrder|OrdemPagamento $order, float $iof): FinPaymentOrder|OrdemPagamento
    {
        $order->forceFill(['valor_iof' => $this->valid($iof)])->save();

        return $order;
    }

    /** @throws DomainException */
    public function instruction(InstrucaoPagamento|InstrucaoPagamentoOutros $instruction, float $iof): InstrucaoPagamento|InstrucaoPagamentoOutros
    {
        $instruction->forceFill(['valor_iof' => $this->valid($iof)])->save();

        return $instruction;
    }

    private function valid(float $iof): float
    {
        return $iof >= 0 ? $iof : throw new DomainException('O IOF não pode ser negativo.');
    }
}
