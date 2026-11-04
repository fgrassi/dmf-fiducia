<?php

namespace App\Actions\Expenses;

use App\Models\Despesa;

/**
 * duplicaDespesa (legado): copia fornecedor, valor, descrição, centro de custo, categoria, data prevista, tipo e empresa;
 * a cópia nasce em aberto, sem pagamento, nota, moeda, observação e arquivos, criada pelo usuário de agora.
 */
class DuplicateExpense
{
    public function __invoke(Despesa $expense, int $userId): Despesa
    {
        return Despesa::create($expense->only([
            'fornecedor_id', 'valor', 'descricao', 'centro_custo_id', 'categoria_despesa_id', 'data_prevista', 'tipo_despesa', 'empresa', 'usuario_id',
        ]) + [
            'tipo_pagamento' => 0,
            'status' => Despesa::STATUS_OPEN,
            'created_by' => $userId,
            'created_at' => today(),
        ]);
    }
}
