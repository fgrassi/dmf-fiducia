<?php

namespace App\Actions\AccountingCovers;

use App\Models\CapaProcesso;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * atualizarResumoResultado (legado): recalcula `rs` e `resultado` dos resumos das capas criadas no período.
 *
 * Diferença do legado: `rs` é o resultado em R$ da capa (que já desconta o custo financeiro); o legado somava o custo
 * financeiro de novo, o que desfaz o desconto (ou o conta em dobro), e não bate com os resumos que o cron gera.
 */
class RefreshCoverSummaries
{
    /** @return int quantidade de resumos atualizados */
    public function __invoke(?CarbonInterface $from = null, ?CarbonInterface $to = null): int
    {
        $covers = CapaProcesso::query()
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('resumo_resultado as r')->whereColumn('r.capa_processo_id', 'capa_processo.capa_process_id'))
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from->copy()->startOfDay()))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to->copy()->endOfDay()))
            ->withItemTotals()
            ->get();

        foreach ($covers as $cover) {
            $result = $cover->result();

            DB::table('resumo_resultado')->where('capa_processo_id', $cover->getKey())->update(['rs' => $result, 'resultado' => $result + $cover->rate()]);
        }

        return $covers->count();
    }
}
