<?php

namespace App\Actions\Expenses;

use App\Models\CategoriaDespesa;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * unirCategoriaDespesa (legado): passa as despesas das categorias de origem para a de destino, move os vencimentos
 * (apaga o que já existe para o mesmo fornecedor no destino) e remove as categorias de origem.
 */
class MergeExpenseCategories
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
            throw new DomainException('Selecione ao menos uma categoria de origem diferente do destino.');
        }

        if (! CategoriaDespesa::query()->whereKey($targetId)->exists()) {
            throw new DomainException('Categoria destino não encontrada.');
        }

        DB::transaction(function () use ($sourceIds, $targetId) {
            DB::table('despesa')->whereIn('categoria_despesa_id', $sourceIds)->update(['categoria_despesa_id' => $targetId]);

            foreach (DB::table('despesa_vencimento')->whereIn('categoria_despesa_id', $sourceIds)->get() as $due) {
                $conflict = DB::table('despesa_vencimento')
                    ->where('fornecedor_id', $due->fornecedor_id)
                    ->where('categoria_despesa_id', $targetId)
                    ->exists();

                $query = DB::table('despesa_vencimento')->where('despesa_vencimento_id', $due->despesa_vencimento_id);
                $conflict ? $query->delete() : $query->update(['categoria_despesa_id' => $targetId]);
            }

            CategoriaDespesa::query()->whereKey($sourceIds)->delete();
        });
    }
}
