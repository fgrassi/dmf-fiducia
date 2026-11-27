<?php

namespace App\Actions\Reports;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * relReserva/list (legado), "Reservas": reservas por cadastro (e outros ~25 filtros) em cinco seções: marítimo (sem LCL), LCL,
 * aéreo, seguro e desembaraço, com os containers de cada reserva e os totais.
 *
 * Diferenças do legado: cada seção usa só os filtros que fazem sentido para ela, em vez dos cortes acidentais da consulta antiga
 * (o LCL e o aéreo deixavam de fora reservas com roro ou outro; o total de tank somava roro); o seguro e o desembaraço seguem só os
 * filtros de cadastro, partes, portos e equipamento, como o legado. Ficam de fora os dois PDFs, trocados pela planilha.
 */
class BookingsReport
{
    private const GROUPS = ['20', '40', 'lcl', 'aereo', 'roro', 'tank', 'outro'];

    private const INSURANCE_EXPENSES = [79, 202, 404, 595];

    /**
     * @param  array<string, mixed>  $filters
     * @return array{maritime: array{rows: list<array<string, mixed>>, totals: array<string, int|float>}, lcl: array{rows: list<array<string, mixed>>, totals: array<string, int|float>}, air: array{rows: list<array<string, mixed>>, totals: array<string, int|float>}, insurance: array{rows: list<array<string, mixed>>}, clearance: array{rows: list<array<string, mixed>>}}
     */
    public function __invoke(array $filters): array
    {
        $sections = $filters['sections'] ?? ['maritime', 'lcl', 'air', 'insurance', 'clearance'];
        $none = ['rows' => [], 'totals' => []];

        return [
            'maritime' => in_array('maritime', $sections, true) ? $this->shipments($filters, 'maritime') : $none,
            'lcl' => in_array('maritime', $sections, true) ? $this->shipments($filters, 'lcl') : $none,
            'air' => in_array('air', $sections, true) ? $this->shipments($filters, 'air') : $none,
            'insurance' => ['rows' => in_array('insurance', $sections, true) ? $this->insurances($filters) : []],
            'clearance' => ['rows' => in_array('clearance', $sections, true) ? $this->clearances($filters) : []],
        ];
    }

    /** @return array{rows: list<array<string, mixed>>, totals: array<string, int|float>} */
    private function shipments(array $filters, string $kind): array
    {
        $hasType = fn (string $type) => fn ($q) => $q->from('mercadoria as m')->join('tipo_container as t', 't.tipo_container_id', '=', 'm.tipo_container_id')
            ->whereColumn('m.embarque_id', 'e.embarque_id')->where('t.tipo', $type);

        $query = $this->base()
            ->leftJoin('navio as n', 'n.navio_id', '=', 'e.navio_id')
            ->leftJoin('armador as a', 'a.armador_id', '=', 'e.armador_id')
            ->where('e.tipo_embarque', $kind === 'air' ? 'a' : 'm')
            ->when($kind === 'maritime', fn ($q) => $q->whereNotExists($hasType('lcl')))
            ->when($kind === 'lcl', fn ($q) => $q->whereExists($hasType('lcl')))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('e.finalizado', $status), fn ($q) => $q->where('e.finalizado', '<>', 'c'));
        $this->common($query, $filters);
        $this->shipping($query, $filters);

        $bookings = $query->orderByDesc('e.saida_prevista')->orderByDesc('e.embarque_id')->get([
            'e.embarque_id', 'e.booking', 'e.invoice', 'e.finalizado', 'e.saida_prevista', 'e.saida_efetiva', 'e.chegada_prevista', 'e.chegada_efetiva',
            'pe.nome as exporter', 'pi.nome as importer', 'a.nome as carrier', 'po.nome as origin', 'pd.nome as destination', 'n.nome as ship',
        ]);

        $goods = DB::table('mercadoria as m')
            ->join('tipo_container as t', 't.tipo_container_id', '=', 'm.tipo_container_id')
            ->whereIn('m.embarque_id', $bookings->pluck('embarque_id'))
            ->get(['m.embarque_id', 't.tipo', 'm.quantidade', 'm.gross_weight', 'm.cubature'])
            ->groupBy('embarque_id');

        $totals = ['bookings' => 0, '20' => 0, '40' => 0, 'roro' => 0, 'tank' => 0, 'outro' => 0, 'kg' => 0.0, 'm3' => 0.0];
        $rows = [];

        foreach ($bookings as $booking) {
            $items = $goods->get($booking->embarque_id, collect());
            $count = fn (string $type) => (int) $items->where('tipo', $type)->sum('quantidade');
            $weight = (float) $items->whereIn('tipo', ['lcl', 'aereo'])->sum('gross_weight');
            $volume = (float) $items->whereIn('tipo', ['lcl', 'aereo'])->sum('cubature');

            $rows[] = $this->row($booking) + [
                'c20' => $count('20') ?: null,
                'c40' => $count('40') ?: null,
                'roro' => $count('roro') ?: null,
                'tank' => $count('tank') ?: null,
                'outro' => $count('outro') ?: null,
                'weight' => $weight,
                'volume' => $volume,
            ];

            if ($booking->finalizado !== 'c') {
                $totals['bookings']++;
                $totals['20'] += $count('20');
                $totals['40'] += $count('40');
                $totals['roro'] += $count('roro');
                $totals['tank'] += $count('tank');
                $totals['outro'] += $count('outro');
                $totals['kg'] += $weight;
                $totals['m3'] += $volume;
            }
        }

        $totals['containers'] = $totals['20'] + $totals['40'] + $totals['roro'] + $totals['tank'] + $totals['outro'];

        return ['rows' => $rows, 'totals' => $totals];
    }

    /** @return list<array<string, mixed>> */
    private function insurances(array $filters): array
    {
        $dedicated = $this->base()
            ->join('insurance_confirmation as ic', 'ic.ref_embarque', '=', 'e.embarque_id')
            ->join('cia_seguro as cs', 'cs.id', '=', 'ic.ref_cia_seguro')
            ->leftJoin('despachante as d', 'd.despachante_id', '=', 'e.despachante_id')
            ->where('e.tipo_embarque', 's');
        $this->common($dedicated, $filters);

        $charged = $this->base()
            ->leftJoin('despachante as d', 'd.despachante_id', '=', 'e.despachante_id')
            ->where('e.tipo_embarque', '<>', 's')
            ->whereExists(fn ($q) => $q->from('fin_expenses_values as f')->whereColumn('f.ref_embarque', 'e.embarque_id')->whereIn('f.ref_expense', self::INSURANCE_EXPENSES)->where('f.selling_rate', '>', 0));
        $this->common($charged, $filters);

        $columns = ['e.embarque_id', 'e.booking', 'e.invoice', 'e.data_topo', 'pe.nome as exporter', 'pi.nome as importer', 'po.nome as origin', 'pd.nome as destination', 'd.nome as broker'];
        $rows = [
            ...$dedicated->orderByDesc('e.embarque_id')->get([...$columns, 'cs.name as insurer', 'ic.valor_fatura as invoice_value', 'ic.valor_frete as freight_value'])->all(),
            ...$charged->orderByDesc('e.embarque_id')->get($columns)->all(),
        ];

        $containers = $this->containers(collect($rows)->pluck('embarque_id'));

        return array_map(fn (object $row) => [
            'id' => $row->embarque_id,
            'booking' => $row->booking,
            'invoice' => $row->invoice,
            'exporter' => $row->exporter,
            'importer' => $row->importer,
            'origin' => $row->origin,
            'destination' => $row->destination,
            'requested_at' => $row->data_topo,
            'broker' => $row->broker,
            'insurer' => $row->insurer ?? null,
            'invoice_value' => $row->invoice_value ?? null,
            'freight_value' => $row->freight_value ?? null,
        ] + $containers->get($row->embarque_id, []), $rows);
    }

    /** @return list<array<string, mixed>> */
    private function clearances(array $filters): array
    {
        $query = $this->base()
            ->leftJoin('despachante as d', 'd.despachante_id', '=', 'e.despachante_id')
            ->where('e.tipo_embarque', 'd')
            ->when($filters['etd_from'] ?? null, fn ($q, $date) => $q->whereDate('e.saida_prevista', '>=', $date))
            ->when($filters['etd_to'] ?? null, fn ($q, $date) => $q->whereDate('e.saida_prevista', '<=', $date))
            ->when($filters['eta_from'] ?? null, fn ($q, $date) => $q->whereDate('e.chegada_prevista', '>=', $date))
            ->when($filters['eta_to'] ?? null, fn ($q, $date) => $q->whereDate('e.chegada_prevista', '<=', $date));
        $this->common($query, $filters);

        return $query->orderByDesc('e.embarque_id')->get(['e.embarque_id', 'e.booking', 'e.invoice', 'e.data_topo', 'e.saida_prevista', 'e.chegada_prevista', 'pe.nome as exporter', 'pi.nome as importer', 'po.nome as origin', 'pd.nome as destination', 'd.nome as broker'])
            ->map(fn (object $row) => [
                'id' => $row->embarque_id,
                'booking' => $row->booking,
                'invoice' => $row->invoice,
                'exporter' => $row->exporter,
                'importer' => $row->importer,
                'origin' => $row->origin,
                'destination' => $row->destination,
                'etd' => $row->saida_prevista,
                'eta' => $row->chegada_prevista,
                'requested_at' => $row->data_topo,
                'broker' => $row->broker,
            ])
            ->all();
    }

    private function base(): Builder
    {
        return DB::table('embarque as e')
            ->leftJoin('pessoa as pe', 'pe.pessoa_id', '=', 'e.pessoa_id_cliente')
            ->leftJoin('pessoa as pi', 'pi.pessoa_id', '=', 'e.pessoa_id_importador')
            ->leftJoin('porto as po', 'po.porto_id', '=', 'e.porto_origem_id')
            ->leftJoin('porto as pd', 'pd.porto_id', '=', 'e.porto_destino_id');
    }

    /** Filtros de todas as seções: cadastro, partes, portos e países, rota e equipamento. */
    private function common(Builder $query, array $filters): void
    {
        $query
            ->when($filters['from'] ?? null, fn ($q, $date) => $q->whereDate('e.data_topo', '>=', $date))
            ->when($filters['to'] ?? null, fn ($q, $date) => $q->whereDate('e.data_topo', '<=', $date))
            ->when(filled($filters['invoice'] ?? null), fn ($q) => $q->where('e.invoice', 'like', "%{$filters['invoice']}%"))
            ->when($filters['operation'] ?? null, fn ($q, $value) => $q->where('e.impor_expor', $value))
            ->when(filled($filters['booking'] ?? null), fn ($q) => $q->where('e.booking', $filters['booking']))
            ->when($filters['company_group'] ?? null, fn ($q, $id) => $q->whereIn('e.cliente_id', fn ($members) => $members->select('pessoa_id')->from('grupo_empresa_membro')->where('grupo_empresa_id', $id)))
            ->when($filters['sellers'] ?? null, fn ($q, array $ids) => $q->whereIn('e.vendedor_id', $ids))
            ->when($filters['exporter'] ?? null, fn ($q, $id) => $q->where('e.pessoa_id_cliente', $id))
            ->when($filters['agent'] ?? null, fn ($q, $id) => $q->where('e.pessoa_id_representante', $id))
            ->when($filters['importer'] ?? null, fn ($q, $id) => $q->where('e.pessoa_id_importador', $id))
            ->when($filters['origin_country'] ?? null, fn ($q, $id) => $q->where('po.ref_pais', $id))
            ->when($filters['origin_port'] ?? null, fn ($q, $id) => $q->where('e.porto_origem_id', $id))
            ->when($filters['destination_country'] ?? null, fn ($q, $id) => $q->where('pd.ref_pais', $id))
            ->when($filters['destination_port'] ?? null, fn ($q, $id) => $q->where('e.porto_destino_id', $id))
            ->when(filled($filters['via'] ?? null), fn ($q) => $q->where('e.via', 'like', "%{$filters['via']}%"))
            ->when(filled($filters['place_of_delivery'] ?? null), fn ($q) => $q->where('e.place_of_delivery', 'like', "%{$filters['place_of_delivery']}%"));

        // com mais de um tipo marcado, basta a reserva ter um deles
        if ($groups = array_intersect(self::GROUPS, $filters['equipment'] ?? [])) {
            $query->whereExists(fn ($q) => $q->from('mercadoria as m')->join('tipo_container as t', 't.tipo_container_id', '=', 'm.tipo_container_id')
                ->whereColumn('m.embarque_id', 'e.embarque_id')->whereIn('t.tipo', $groups));
        }
    }

    /** Filtros só de reservas marítimas e aéreas: armador, navio, despachante, datas previstas e efetivas, fechamento. */
    private function shipping(Builder $query, array $filters): void
    {
        $query
            ->when($filters['carrier'] ?? null, fn ($q, $id) => $q->where('e.armador_id', $id))
            ->when($filters['ship'] ?? null, fn ($q, $id) => $q->where('e.navio_id', $id))
            ->when($filters['client'] ?? null, fn ($q, $id) => $q->where('e.cliente_id', $id))
            ->when($filters['broker'] ?? null, fn ($q, $id) => $q->where('e.despachante_id', $id))
            ->when($filters['dmf_broker'] ?? null, fn ($q, $id) => $q->where('e.despachante_dmf_id', $id))
            ->when($filters['loading_type'] ?? null, fn ($q, $value) => $q->where('e.loading_type', $value))
            ->when($filters['closing_type'] ?? null, fn ($q, $value) => $q->where('e.tipo_fechamento', $value))
            ->when(filled($filters['closing_by'] ?? null), fn ($q) => $q->whereRaw('UPPER(e.de) = ?', [mb_strtoupper($filters['closing_by'])]))
            ->when($filters['exit_from'] ?? null, fn ($q, $date) => $q->whereDate('e.saida_efetiva', '>=', $date))
            ->when($filters['exit_to'] ?? null, fn ($q, $date) => $q->whereDate('e.saida_efetiva', '<=', $date))
            ->when($filters['arrival_from'] ?? null, fn ($q, $date) => $q->whereDate('e.chegada_efetiva', '>=', $date))
            ->when($filters['arrival_to'] ?? null, fn ($q, $date) => $q->whereDate('e.chegada_efetiva', '<=', $date))
            ->when($filters['etd_from'] ?? null, fn ($q, $date) => $q->whereDate('e.saida_prevista', '>=', $date))
            ->when($filters['etd_to'] ?? null, fn ($q, $date) => $q->whereDate('e.saida_prevista', '<=', $date))
            ->when($filters['eta_from'] ?? null, fn ($q, $date) => $q->whereDate('e.chegada_prevista', '>=', $date))
            ->when($filters['eta_to'] ?? null, fn ($q, $date) => $q->whereDate('e.chegada_prevista', '<=', $date));
    }

    /** @return array<string, mixed> */
    private function row(object $booking): array
    {
        return [
            'id' => $booking->embarque_id,
            'exporter' => $booking->exporter,
            'importer' => $booking->importer,
            'booking' => $booking->booking,
            'carrier' => $booking->carrier,
            'invoice' => $booking->invoice,
            'destination' => $booking->destination,
            'origin' => $booking->origin,
            'ship' => $booking->ship,
            'etd' => $booking->saida_prevista,
            'exit' => $booking->saida_efetiva,
            'eta' => $booking->chegada_prevista,
            'arrival' => $booking->chegada_efetiva,
            'status' => $booking->finalizado,
        ];
    }

    /** Containers por tipo de cada reserva, para as seções de seguro. @return Collection<int, array<string, int|null>> */
    private function containers(Collection $bookingIds): Collection
    {
        return DB::table('mercadoria as m')
            ->join('tipo_container as t', 't.tipo_container_id', '=', 'm.tipo_container_id')
            ->whereIn('m.embarque_id', $bookingIds)
            ->get(['m.embarque_id', 't.tipo', 'm.quantidade'])
            ->groupBy('embarque_id')
            ->map(fn (Collection $items) => [
                'c20' => (int) $items->where('tipo', '20')->sum('quantidade') ?: null,
                'c40' => (int) $items->where('tipo', '40')->sum('quantidade') ?: null,
                'roro' => (int) $items->where('tipo', 'roro')->sum('quantidade') ?: null,
                'tank' => (int) $items->where('tipo', 'tank')->sum('quantidade') ?: null,
                'outro' => (int) $items->where('tipo', 'outro')->sum('quantidade') ?: null,
            ]);
    }
}
