<?php

namespace App\Actions\Reports;

use Illuminate\Support\Facades\DB;

/**
 * relTransitTime/transitTimePorto (legado), "Transit Time - Destino": por armador, para a origem e/ou o destino escolhidos, a
 * média do transit time estimado (chegada ÷ saída previstas) e do efetivo, e o atraso. Lê a view vw_transit_time, só com
 * reservas de datas previstas e efetivas completas. Sem origem nem destino não consulta, como no legado.
 */
class TransitTimeByPortReport
{
    /**
     * @return list<array{carrier: string, origin: string, destination: string, originCountry: string, destinationCountry: string, count: int, planned: float, actual: float, delay: float}>
     */
    public function __invoke(?int $originId, ?int $destinationId): array
    {
        if (! $originId && ! $destinationId) {
            return [];
        }

        $planned = SqlDays::between('chegada_prevista', 'saida_prevista');
        $actual = SqlDays::between('chegada_efetiva', 'saida_efetiva');

        return DB::table('vw_transit_time')
            ->whereNotNull('saida_prevista')
            ->whereNotNull('chegada_prevista')
            ->whereNotNull('chegada_efetiva')
            ->whereNotNull('saida_efetiva')
            ->when($originId, fn ($q) => $q->where('origem_id', $originId))
            ->when($destinationId, fn ($q) => $q->where('destino_id', $destinationId))
            ->groupBy('armador', 'origem', 'destino', 'pais_origem', 'pais_destino')
            ->orderBy('origem')
            ->orderBy('destino')
            ->orderByRaw("AVG({$actual}) - AVG({$planned})")
            ->orderBy('armador')
            ->selectRaw("armador, origem, destino, pais_origem, pais_destino, COUNT(*) as total, AVG({$planned}) as planned, COALESCE(AVG({$actual}), 0) as actual")
            ->get()
            ->map(fn ($row) => [
                'carrier' => (string) $row->armador,
                'origin' => (string) $row->origem,
                'destination' => (string) $row->destino,
                'originCountry' => (string) $row->pais_origem,
                'destinationCountry' => (string) $row->pais_destino,
                'count' => (int) $row->total,
                'planned' => (float) $row->planned,
                'actual' => (float) $row->actual,
                'delay' => (float) $row->actual - (float) $row->planned,
            ])
            ->all();
    }
}
