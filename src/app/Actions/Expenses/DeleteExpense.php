<?php

namespace App\Actions\Expenses;

use App\Models\Despesa;
use Illuminate\Support\Facades\DB;

/** removeDespesa (legado): apaga os vínculos de arquivo e a despesa; os arquivos em disco ficam, como no legado. */
class DeleteExpense
{
    public function __invoke(Despesa $expense): void
    {
        DB::transaction(function () use ($expense) {
            $expense->files()->delete();
            $expense->delete();
        });
    }
}
