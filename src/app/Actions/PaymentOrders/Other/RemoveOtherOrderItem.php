<?php

namespace App\Actions\PaymentOrders\Other;

use App\Models\OrdemPagamentoItem;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/** removeItem (legado, Outros): só o admin tira uma despesa da ordem, e só se ela não está em instrução de pagamento. */
class RemoveOtherOrderItem
{
    public function __invoke(OrdemPagamentoItem $item, User $user): void
    {
        if (! $user->hasLegacyCredential('admin')) {
            throw new DomainException('Apenas administradores podem remover despesas.');
        }

        if (DB::table('instrucao_pagamento_item_outros')->where('ordem_pagamento_item_id', $item->getKey())->exists()) {
            throw new DomainException('A despesa já está em uma Instrução de Pagamento. Exclua a instrução antes de removê-la.');
        }

        $item->delete();
    }
}
