<?php

namespace App\Actions\AccountingCovers;

use App\Models\CapaProcesso;
use App\Models\CapaProcessoManual;
use Illuminate\Support\Facades\DB;

/**
 * saveCapaManual e editSellingItem/editBuyingItem/editMoeda* (legado): cria ou altera a capa manual e acerta o resumo_resultado, se houver.
 *
 * Diferença do legado: ao editar, a data de criação e o autor ficam como estão (o legado os sobrescreve, o que joga a
 * capa editada para "Recentes").
 */
class SaveManualCover
{
    /** @param  array<string, mixed>  $data */
    public function __invoke(array $data, ?CapaProcessoManual $cover = null, ?int $userId = null): CapaProcessoManual
    {
        return DB::transaction(function () use ($data, $cover, $userId) {
            if ($cover) {
                $cover->update($data);
            } else {
                $cover = CapaProcessoManual::create($data + ['numero' => $this->nextNumber(), 'created_at' => now(), 'created_by' => $userId]);
            }

            $this->refreshSummary($cover);

            return $cover;
        });
    }

    /** getMaiorNumero (legado): maior número entre as capas (normais e manuais) ainda sem pasta, mais um. */
    private function nextNumber(): int
    {
        return 1 + (int) max(
            CapaProcesso::whereNull('capa_processo_arquivo_id')->max('numero'),
            CapaProcessoManual::whereNull('capa_processo_arquivo_id')->max('numero'),
        );
    }

    private function refreshSummary(CapaProcessoManual $cover): void
    {
        $result = $cover->result();

        DB::table('resumo_resultado')->where('capa_processo_manual_id', $cover->getKey())->update([
            'usd' => $cover->moeda_usd_eur == 2 ? $cover->valor_usd_eur : 0,
            'euro' => $cover->moeda_usd_eur == 3 ? $cover->valor_usd_eur : 0,
            'rs' => $result,
            'resultado' => $result + $cover->rate(),
        ]);
    }
}
