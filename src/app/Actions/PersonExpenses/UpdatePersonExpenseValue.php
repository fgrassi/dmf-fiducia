<?php

namespace App\Actions\PersonExpenses;

use App\Models\MatrizDespesaPessoa;
use App\Models\User;

/**
 * relatorios/atualizarValorMatrizDespesa (legado): troca só o valor de venda (S/R) da despesa, direto na linha do relatório.
 * Diferenças do legado: o valor chega como número (o legado tirava os pontos e trocava a vírgula, então "1234.56" virava
 * 123456) e a data da alteração também é gravada.
 */
class UpdatePersonExpenseValue
{
    public function __invoke(MatrizDespesaPessoa $expense, float $value, User $user): void
    {
        $expense->forceFill(['expense_value' => $value, 'updated_by' => $user->getKey(), 'updated_at' => today()])->save();
    }
}
