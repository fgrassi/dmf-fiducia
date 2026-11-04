<?php

namespace App\Actions\Expenses;

use App\Models\Despesa;

/**
 * confirmaPagamento (legado): grava os dados do pagamento, o autor e a data da atualização e encerra a despesa. O banco
 * só é trocado se vier preenchido.
 */
class ConfirmExpensePayment
{
    /**
     * @param  array{data_efetiva:string, tipo_pagamento:int|string, banco_id?:int|string|null, nota_fiscal?:?string, juros?:?float, multa?:?float, desconto?:?float, moeda_id?:int|string|null, taxa?:?float, valor:float|string}  $data
     */
    public function __invoke(Despesa $expense, array $data, int $userId): Despesa
    {
        if (blank($data['banco_id'] ?? null)) {
            unset($data['banco_id']);
        }

        $expense->update($data + [
            'status' => Despesa::STATUS_CLOSED,
            'updated_by' => $userId,
            'updated_at' => now(),
        ]);

        return $expense;
    }
}
