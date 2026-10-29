<?php

namespace App\Actions\PaymentOrders;

use App\Models\InstrucaoPagamento;
use Illuminate\Support\Facades\DB;

/** delete (legado): apaga a instrução e os itens dela; os itens da ordem voltam a poder ser recalculados. */
class DeletePaymentInstruction
{
    public function __invoke(InstrucaoPagamento $instruction): void
    {
        DB::transaction(function () use ($instruction) {
            $instruction->items()->delete();
            $instruction->delete();
        });
    }
}
