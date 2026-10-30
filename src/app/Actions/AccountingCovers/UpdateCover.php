<?php

namespace App\Actions\AccountingCovers;

use App\Models\CapaProcesso;
use Illuminate\Support\Facades\DB;

/** editNumero/Moeda/CustoFinanceiro/Receita/Comissao/Cotacao/DataCapa (legado): grava a capa e acerta o resumo_resultado, se houver. */
class UpdateCover
{
    /** Campos que o legado refletia no resumo_resultado (número e data ficam de fora, como lá). */
    private const SUMMARY_FIELDS = ['moeda_id', 'custo_financeiro', 'receitas', 'comissoes', 'valor_cotacao'];

    /** @param  array<string, mixed>  $attributes */
    public function __invoke(CapaProcesso $cover, array $attributes): CapaProcesso
    {
        return DB::transaction(function () use ($cover, $attributes) {
            $cover->update($attributes);

            if (array_intersect_key($attributes, array_flip(self::SUMMARY_FIELDS))) {
                $this->refreshSummary($cover);
            }

            return $cover;
        });
    }

    private function refreshSummary(CapaProcesso $cover): void
    {
        $result = $cover->result();

        DB::table('resumo_resultado')->where('capa_processo_id', $cover->getKey())->update([
            'usd' => $cover->moeda_id == 2 ? $cover->receitas : 0,
            'euro' => $cover->moeda_id == 3 ? $cover->receitas : 0,
            'rs' => $result,
            'resultado' => $result + $cover->rate(),
        ]);
    }
}
