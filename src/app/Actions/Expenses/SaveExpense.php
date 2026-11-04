<?php

namespace App\Actions\Expenses;

use App\Models\Despesa;

/**
 * addDespesa e as edições na linha (legado): a despesa nova entra em aberto, com autor e data de criação; a edição só
 * grava os campos (o legado também não mexe em `updated_by` fora do pagamento, nem reabre a despesa já encerrada).
 */
class SaveExpense
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(array $data, int $userId, ?Despesa $expense = null): Despesa
    {
        if ($expense) {
            return tap($expense)->update($data);
        }

        return Despesa::create($data + [
            'status' => Despesa::STATUS_OPEN,
            'usuario_id' => $userId,
            'created_by' => $userId,
            'created_at' => now(),
        ]);
    }
}
