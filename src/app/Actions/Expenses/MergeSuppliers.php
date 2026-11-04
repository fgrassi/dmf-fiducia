<?php

namespace App\Actions\Expenses;

use App\Models\Fornecedor;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * "Unir selecionados" dos fornecedores (sem equivalente no legado; mesma regra de MergeExpenseCategories): passa as
 * despesas dos fornecedores de origem para o de destino, move os vencimentos (apaga o que já existe para a mesma
 * categoria no destino) e remove os fornecedores de origem.
 */
class MergeSuppliers
{
    /**
     * @param  list<int|string>  $sourceIds
     *
     * @throws DomainException
     */
    public function __invoke(array $sourceIds, int $targetId): void
    {
        $sourceIds = array_values(array_diff(array_map('intval', $sourceIds), [$targetId, 0]));

        if ($sourceIds === []) {
            throw new DomainException('Selecione ao menos um fornecedor de origem diferente do destino.');
        }

        if (! Fornecedor::query()->whereKey($targetId)->exists()) {
            throw new DomainException('Fornecedor destino não encontrado.');
        }

        DB::transaction(function () use ($sourceIds, $targetId) {
            DB::table('despesa')->whereIn('fornecedor_id', $sourceIds)->update(['fornecedor_id' => $targetId]);

            foreach (DB::table('despesa_vencimento')->whereIn('fornecedor_id', $sourceIds)->get() as $due) {
                $conflict = DB::table('despesa_vencimento')
                    ->where('fornecedor_id', $targetId)
                    ->where('categoria_despesa_id', $due->categoria_despesa_id)
                    ->exists();

                $query = DB::table('despesa_vencimento')->where('despesa_vencimento_id', $due->despesa_vencimento_id);
                $conflict ? $query->delete() : $query->update(['fornecedor_id' => $targetId]);
            }

            Fornecedor::query()->whereKey($sourceIds)->delete();
        });
    }
}
