<?php

namespace App\Actions\Carriers;

use App\Models\CmnArmadorPorto;
use App\Models\FinExpensesMatrix;
use App\Models\User;

/**
 * addExpense (legado): inclui uma despesa na matriz do armador-porto, uma linha por equipamento escolhido (nenhum:
 * uma linha sem equipamento). Cada linha criada passa por MatrixChangeNotice, como o `verificaDespesa` do legado.
 */
class AddMatrixExpenses
{
    public function __construct(private MatrixChangeNotice $notice) {}

    /**
     * @param  array<string, mixed>  $data  campos do formulário (ref_equipamento: lista de equipamentos)
     * @return list<FinExpensesMatrix>
     */
    public function __invoke(CmnArmadorPorto $port, array $data, User $user): array
    {
        $equipments = array_filter((array) ($data['ref_equipamento'] ?? [])) ?: [null];

        $created = [];

        foreach ($equipments as $equipment) {
            $expense = FinExpensesMatrix::create([
                'ref_armador_porto' => $port->getKey(),
                'ref_equipamento' => $equipment,
                'ref_expense' => $data['ref_expense'],
                'ref_currency' => $data['ref_currency'],
                'type' => $data['type'],
                'billing' => $data['billing'],
                'expense_value' => $data['expense_value'],
                'imo_value' => $data['imo_value'] ?: 0,
                'compra_minimo' => $data['compra_minimo'] ?: null,
                'venda_minimo' => $data['venda_minimo'] ?: null,
                'pais_origem_id' => $data['pais_origem_id'] ?: null,
                'due_from' => $data['due_from'],
                'due_to' => $data['due_to'] ?: null,
                'created_at' => now(),
                'created_by' => $user->getKey(),
            ]);

            ($this->notice)($expense, $user->nome ?: $user->login);

            $created[] = $expense;
        }

        return $created;
    }
}
