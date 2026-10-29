<?php

namespace App\Actions\PaymentOrders\Other;

use App\Models\InstrucaoPagamentoItemOutros;
use App\Models\InstrucaoPagamentoOutros;
use Illuminate\Support\Facades\DB;

/** delete (legado, Outros): apaga a instrução e os itens dela. Como no legado, o vínculo de parte fica órfão. */
class DeleteOtherPaymentInstruction
{
    public function __invoke(InstrucaoPagamentoOutros $instruction): void
    {
        DB::transaction(function () use ($instruction) {
            InstrucaoPagamentoItemOutros::where('instrucao_pagamento_outros_id', $instruction->getKey())->delete();
            $instruction->delete();
        });
    }
}
