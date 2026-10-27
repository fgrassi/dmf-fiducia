<?php

namespace App\Actions\Cobranca\NfseRules;

use App\Models\FinClientsNfseRegra;
use Illuminate\Support\Facades\DB;

/**
 * nfseRegraCliente/add (legado): uma regra por cliente (salvar de novo para o mesmo cliente atualiza a existente).
 * Os grupos de despesas só valem com "Separar NFS por Grupo de Despesas".
 */
class SaveNfseRule
{
    /** @param  array{ref_client:int, tomador:string, justificativa:string, bloquear_emissao?:bool, separar_nf_grupo_despesas?:bool, expense_rules?:list<int>}  $data */
    public function __invoke(array $data, int $userId, ?FinClientsNfseRegra $rule = null): FinClientsNfseRegra
    {
        $rule ??= FinClientsNfseRegra::firstOrNew(['ref_client' => $data['ref_client']]);
        $split = (bool) ($data['separar_nf_grupo_despesas'] ?? false);

        return DB::transaction(function () use ($rule, $data, $userId, $split) {
            $rule->forceFill([
                'ref_client' => $data['ref_client'],
                'tomador' => $data['tomador'],
                'justificativa' => $data['justificativa'],
                'bloquear_emissao' => (bool) ($data['bloquear_emissao'] ?? false),
                'separar_nf_grupo_despesas' => $split,
                'updated_at' => now(),
                'updated_by' => $userId,
            ]);

            if (! $rule->exists) {
                $rule->forceFill(['created_at' => now(), 'created_by' => $userId]);
            }

            $rule->save();
            $rule->expenseRules()->sync($split ? ($data['expense_rules'] ?? []) : []);

            return $rule;
        });
    }
}
