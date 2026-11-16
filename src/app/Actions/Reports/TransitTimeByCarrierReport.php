<?php

namespace App\Actions\Reports;

use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * relTransitTime/transitTimeArmador (legado), "Transit Time - Armador": por armador e trecho (origem e destino), a média do
 * transit time inicial (ETA ÷ ETD iniciais) e do efetivo (chegada ÷ saída efetivas) das reservas com saída efetiva no período
 * e as quatro datas preenchidas, e o atraso (efetivo menos inicial).
 */
class TransitTimeByCarrierReport
{
    /**
     * @param  list<int>  $originCountries
     * @param  list<int>  $destinationCountries
     * @return list<array{carrier: string, origin: string, destination: string, originCountry: string, destinationCountry: string, count: int, planned: float, actual: float, delay: float}>
     *
     * @throws DomainException
     */
    public function __invoke(?string $from, ?string $to, ?string $type = null, ?int $carrierId = null, ?int $originId = null, ?int $destinationId = null, array $originCountries = [], array $destinationCountries = []): array
    {
        throw_if(blank($from), DomainException::class, 'Informe a data inicial da saída efetiva.');

        $planned = SqlDays::between('e.eta_inicial', 'e.etd_inicial');
        $actual = SqlDays::between('e.chegada_efetiva', 'e.saida_efetiva');

        return DB::table('embarque as e')
            ->join('porto as po', 'po.porto_id', '=', 'e.porto_origem_id')
            ->join('porto as pd', 'pd.porto_id', '=', 'e.porto_destino_id')
            ->join('armador as a', 'a.armador_id', '=', 'e.armador_id')
            ->join('paises as pao', 'pao.paises_id', '=', 'po.ref_pais')
            ->join('paises as pad', 'pad.paises_id', '=', 'pd.ref_pais')
            ->whereNotNull('e.saida_efetiva')
            ->whereNotNull('e.chegada_efetiva')
            ->whereNotNull('e.eta_inicial')
            ->whereNotNull('e.etd_inicial')
            ->where('e.saida_efetiva', '>=', $from)
            ->when($to, fn ($q) => $q->where('e.saida_efetiva', '<=', $to))
            ->when(in_array($type, ['m', 'a'], true), fn ($q) => $q->where('e.tipo_embarque', $type))
            ->when($carrierId, fn ($q) => $q->where('e.armador_id', $carrierId))
            ->when($originId, fn ($q) => $q->where('e.porto_origem_id', $originId))
            ->when($destinationId, fn ($q) => $q->where('e.porto_destino_id', $destinationId))
            ->when($originCountries, fn ($q) => $q->whereIn('pao.paises_id', $originCountries))
            ->when($destinationCountries, fn ($q) => $q->whereIn('pad.paises_id', $destinationCountries))
            ->groupBy('a.nome', 'po.nome', 'pd.nome', 'pao.nome', 'pad.nome')
            ->orderBy('a.nome')
            ->orderBy('po.nome')
            ->orderBy('pd.nome')
            ->selectRaw("a.nome as carrier, po.nome as origin, pd.nome as destination, pao.nome as origin_country, pad.nome as destination_country, COUNT(*) as total, AVG({$planned}) as planned, AVG({$actual}) as actual")
            ->get()
            ->map(fn ($row) => [
                'carrier' => $row->carrier,
                'origin' => $row->origin,
                'destination' => $row->destination,
                'originCountry' => $row->origin_country,
                'destinationCountry' => $row->destination_country,
                'count' => (int) $row->total,
                'planned' => (float) $row->planned,
                'actual' => (float) $row->actual,
                'delay' => (float) $row->actual - (float) $row->planned,
            ])
            ->all();
    }
}
