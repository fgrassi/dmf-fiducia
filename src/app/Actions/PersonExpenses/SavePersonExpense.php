<?php

namespace App\Actions\PersonExpenses;

use App\Models\MatrizDespesaPessoa;
use App\Models\User;

/**
 * pessoa/saveExpense e agente/saveExpense (legado): grava a despesa da matriz do cliente ou agente. As outras despesas vigentes
 * da pessoa (sem fim de validade) com a mesma despesa, moeda e cobrança, e o mesmo equipamento e porto quando esta os tem, são
 * encerradas com o início da validade desta.
 *
 * Diferenças do legado: a edição não regrava `created_at` e `created_by`; e o agente, que no legado não olhava o porto ao
 * encerrar, passa a seguir a regra da pessoa.
 */
class SavePersonExpense
{
    private const NULLABLE = ['ref_equipamento', 'porto_id', 'due_to', 'min_value', 'moeda_compra_id', 'valor_compra'];

    /** @param  array<string, mixed>  $data */
    public function __invoke(array $data, User $user, ?MatrizDespesaPessoa $expense = null): MatrizDespesaPessoa
    {
        foreach (self::NULLABLE as $field) {
            $data[$field] = filled($data[$field] ?? null) ? $data[$field] : null;
        }

        $expense ??= new MatrizDespesaPessoa(['created_at' => today(), 'created_by' => $user->getKey()]);

        if ($expense->exists) {
            $expense->updated_at = today();
            $expense->updated_by = $user->getKey();
            unset($data['pessoa_id']);
        }

        $expense->fill($data)->save();

        MatrizDespesaPessoa::query()
            ->where('pessoa_id', $expense->pessoa_id)
            ->whereNull('due_to')
            ->where('ref_expense', $expense->ref_expense)
            ->where('ref_currency', $expense->ref_currency)
            ->where('billing', $expense->billing)
            ->whereKeyNot($expense->getKey())
            ->when($expense->ref_equipamento, fn ($query, $equipment) => $query->where('ref_equipamento', $equipment))
            ->when($expense->porto_id, fn ($query, $port) => $query->where('porto_id', $port))
            ->update(['due_to' => $expense->due_from]);

        return $expense;
    }
}
