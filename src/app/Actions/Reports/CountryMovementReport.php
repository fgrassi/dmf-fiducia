<?php

namespace App\Actions\Reports;

use Illuminate\Support\Facades\DB;

/**
 * insights/index (legado), "Movimento por País": embarques e contêineres por país no período da saída prevista, sem os cancelados.
 * O país é o da origem nas importações e o do destino nas exportações. Com "embarques confirmados" só entram os que têm
 * confirmação de saída. Ordem do legado: contêineres, embarques e nome.
 */
class CountryMovementReport
{
    /**
     * @param  array{data_ini: string, data_fim: string, impor_expor?: ?string, pessoa_id?: int|string|null, pais_id?: int|string|null, porto_origem_id?: int|string|null, porto_destino_id?: int|string|null, embarque_confirmado?: bool|null}  $filters
     * @return array{rows: list<array{country: string, shipments: int, shipmentsPercent: float, containers: int, containersPercent: float}>, shipments: int, containers: int}
     */
    public function __invoke(array $filters): array
    {
        $type = $filters['impor_expor'] ?? null;

        $rows = DB::table('paises as p')
            ->join('porto as po', 'po.ref_pais', '=', 'p.paises_id')
            ->join('embarque as e', fn ($join) => match ($type) {
                'i' => $join->on('e.porto_origem_id', '=', 'po.porto_id'),
                'e' => $join->on('e.porto_destino_id', '=', 'po.porto_id'),
                default => $join->whereRaw("((e.impor_expor = 'i' AND e.porto_origem_id = po.porto_id) OR (e.impor_expor = 'e' AND e.porto_destino_id = po.porto_id))"),
            })
            ->when($filters['embarque_confirmado'] ?? false, fn ($q) => $q->join('departure_confirmation as dc', 'dc.ref_embarque', '=', 'e.embarque_id'))
            ->leftJoin('mercadoria as m', 'm.embarque_id', '=', 'e.embarque_id')
            ->where('e.finalizado', '<>', 'c')
            ->where('e.saida_prevista', '>=', $filters['data_ini'])
            ->where('e.saida_prevista', '<=', $filters['data_fim'])
            ->when($type, fn ($q) => $q->where('e.impor_expor', $type))
            ->when($filters['pais_id'] ?? null, fn ($q, $id) => $q->where('p.paises_id', $id))
            ->when($filters['pessoa_id'] ?? null, fn ($q, $id) => $q->where('e.cliente_id', $id))
            ->when($filters['porto_origem_id'] ?? null, fn ($q, $id) => $q->where('e.porto_origem_id', $id))
            ->when($filters['porto_destino_id'] ?? null, fn ($q, $id) => $q->where('e.porto_destino_id', $id))
            ->groupBy('p.paises_id', 'p.nome', 'p.uncode')
            ->orderByRaw('COALESCE(SUM(m.quantidade), 0) DESC')
            ->orderByRaw('COUNT(DISTINCT e.embarque_id) DESC')
            ->orderBy('p.nome')
            ->selectRaw('p.nome, p.uncode, COUNT(DISTINCT e.embarque_id) as total_embarques, COALESCE(SUM(m.quantidade), 0) as total_containers')
            ->get();

        $shipments = (int) $rows->sum('total_embarques');
        $containers = (int) $rows->sum('total_containers');

        return [
            'rows' => $rows->map(fn ($row) => [
                'country' => $row->nome.(strlen((string) $row->uncode) ? " ({$row->uncode})" : ''),
                'shipments' => (int) $row->total_embarques,
                'shipmentsPercent' => $shipments ? $row->total_embarques / $shipments * 100 : 0.0,
                'containers' => (int) $row->total_containers,
                'containersPercent' => $containers ? $row->total_containers / $containers * 100 : 0.0,
            ])->all(),
            'shipments' => $shipments,
            'containers' => $containers,
        ];
    }
}
