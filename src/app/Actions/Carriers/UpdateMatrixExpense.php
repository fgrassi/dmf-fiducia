<?php

namespace App\Actions\Carriers;

use App\Models\FinExpensesMatrix;
use App\Models\User;

/**
 * editDueTo, editValorExpense, editTipo etc. (legado): altera uma despesa da matriz. O legado grava updated_at/by em
 * todas as edições, menos nas de custo mínimo e venda mínima (editCompraMinimo, editVendaMinimo).
 */
class UpdateMatrixExpense
{
    private const WITHOUT_AUDIT = ['compra_minimo', 'venda_minimo'];

    /** @param  array<string, mixed>  $data */
    public function __invoke(FinExpensesMatrix $expense, array $data, User $user): FinExpensesMatrix
    {
        $expense->fill($data);

        if (array_diff(array_keys($expense->getDirty()), self::WITHOUT_AUDIT) !== []) {
            $expense->updated_at = now();
            $expense->updated_by = $user->getKey();
        }

        $expense->save();

        return $expense;
    }
}
