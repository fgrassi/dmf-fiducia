<?php

namespace App\Actions\ExpenseRules;

use App\Models\RegraDespesaCobranca;
use Illuminate\Support\Facades\DB;

/** regraCobranca/remove (legado): apaga o grupo com as despesas vinculadas e os vínculos das regras de NFS-e, numa transação. */
class DeleteExpenseRule
{
    public function __invoke(RegraDespesaCobranca $rule): void
    {
        DB::transaction(function () use ($rule) {
            DB::table('fin_clients_nfse_regra_despesa')->where('regra_despesa_cobranca_id', $rule->getKey())->delete();
            $rule->expenses()->detach();
            $rule->delete();
        });
    }
}
