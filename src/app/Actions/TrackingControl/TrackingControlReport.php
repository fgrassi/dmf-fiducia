<?php

namespace App\Actions\TrackingControl;

use App\Models\Embarque;
use Illuminate\Support\Facades\DB;

/**
 * controleTracking/loadImpo e loadExpo (legado), "Controle de Tracking": reservas marítimas em andamento com confirmação
 * de embarque, cada uma com a data do último tracking lançado.
 * IMPO: abertas, ou chegadas/encerradas ainda sem confirmação de chegada (as prioritárias primeiro).
 * EXPO: sem chegada efetiva, cadastradas desde 2020 (reservas antigas não têm chegada efetiva).
 * Diferença do legado: o Portal Integrador (mensagens não lidas do armador) fica fora até esse módulo ser portado.
 */
class TrackingControlReport
{
    private const EXPORT_SINCE = '2020-01-01';

    /** Respostas automáticas de cobrança/CE que não contam como tracking (Embarque::getUltimoTrackingExpo). */
    private const IGNORED_TRACKING = ['Payment request%', 'Payment received%', 'INFORMAMOS QUE O CE MERCANTE REFERENTE%'];

    /** @return list<array<string, mixed>> */
    public function __invoke(string $operation): array
    {
        $bookings = DB::table('embarque as e')
            ->leftJoin('pessoa as p', 'p.pessoa_id', '=', 'e.cliente_id')
            ->leftJoin('armador as a', 'a.armador_id', '=', 'e.armador_id')
            ->leftJoin('porto as pd', 'pd.porto_id', '=', 'e.porto_destino_id')
            ->leftJoin('paises as pa', 'pa.paises_id', '=', 'pd.ref_pais')
            ->leftJoin('navio as n', 'n.navio_id', '=', 'e.navio_id')
            ->leftJoin('navio as nd', 'nd.navio_id', '=', 'e.navio_id_destino')
            ->where('e.impor_expor', $operation)
            ->where('e.tipo_embarque', 'm')
            ->where('e.finalizado', '<>', 'c')
            ->whereExists(fn ($q) => $q->from('departure_confirmation as d')->whereColumn('d.ref_embarque', 'e.embarque_id'))
            ->when($operation === 'i', fn ($q) => $q
                ->where(fn ($q) => $q
                    ->where('e.finalizado', 'n')
                    ->orWhere(fn ($q) => $q
                        ->whereIn('e.finalizado', ['e', 's'])
                        ->whereNotExists(fn ($q) => $q->from('arrival_confirmation as ac')->whereColumn('ac.ref_embarque', 'e.embarque_id'))))
                ->orderByDesc('e.flag_prioritario'))
            ->when($operation === 'e', fn ($q) => $q
                ->whereNull('e.chegada_efetiva')
                ->where('e.dteCadastro', '>=', self::EXPORT_SINCE))
            ->get(['e.embarque_id', 'e.booking', 'e.master_bl', 'e.chegada_prevista', 'e.free_time', 'e.finalizado', 'e.flag_prioritario', 'p.nome as client', 'a.nome as carrier', 'pd.nome as port', 'pa.nome as country', 'n.nome as ship', 'nd.nome as destination_ship']);

        $ids = $bookings->pluck('embarque_id');
        $blRows = DB::table('bl')->whereIn('embarque_id', $ids)->get(['bl_id', 'embarque_id', 'mbl_number'])->groupBy('embarque_id');
        $containers = DB::table('container')->whereIn('bl_id', $blRows->flatten(1)->pluck('bl_id'))->orderBy('container_id')->get(['bl_id', 'numero'])->groupBy('bl_id');
        $lastTracking = DB::table('transborda')
            ->whereIn('embarque_id', $ids)
            ->where(function ($q) {
                foreach (self::IGNORED_TRACKING as $text) {
                    $q->where('descr', 'not like', $text);
                }
            })
            ->groupBy('embarque_id')
            ->pluck(DB::raw('max(data_reg)'), 'embarque_id');

        return $bookings->map(fn ($e) => [
            'id' => $e->embarque_id,
            'priority' => (bool) $e->flag_prioritario,
            'booking' => $e->booking,
            'client' => $e->client,
            'carrier' => $e->carrier,
            'arrival' => $e->chegada_prevista,
            'destination' => $e->port ? $e->port.($e->country ? " ({$e->country})" : '') : null,
            'mbl' => $operation === 'i'
                ? $e->master_bl
                : $blRows->get($e->embarque_id, collect())->pluck('mbl_number')->filter()->implode(', '),
            'containers' => $blRows->get($e->embarque_id, collect())
                ->flatMap(fn ($bl) => $containers->get($bl->bl_id, collect())->pluck('numero'))
                ->filter()
                ->implode(', '),
            'ships' => $e->ship || $e->destination_ship ? "{$e->ship}/{$e->destination_ship}" : null,
            'free_time' => $e->free_time,
            'status' => Embarque::STATUSES[$e->finalizado] ?? null,
            'last_tracking' => $lastTracking[$e->embarque_id] ?? null,
        ])->all();
    }
}
