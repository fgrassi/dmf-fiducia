<?php

namespace App\Actions\Reports;

use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * relConfReservas (legado), "Movimento Diário": reservas cadastradas no período (exceto cancelamentos, seguro, desembaraço e
 * correio) com os containers, os seguros e desembaraços à parte, os totais e as cotações criadas no mesmo período.
 *
 * Reservas com seguro ou THC cobrado (despesas 79, 202, 404 e 595 com venda) aparecem de novo como "Seguro", como no legado.
 * Fica de fora o botão de e-mail hora a hora da tela antiga, que não fazia nada.
 */
class DailyMovementReport
{
    private const INSURANCE_EXPENSES = [79, 202, 404, 595];

    /** Despesas "Taxa Administrativa" (BL, container e processo): marcam a reserva com estrela. */
    private const ADMIN_FEES = [618, 1135, 1444];

    /** Tipo de container LCL e o tipo de equipamento que cada acumulador usa. */
    private const LCL_CONTAINER = 20;

    /**
     * @return array{
     *     bookings: list<array<string, mixed>>, insurances: list<array<string, mixed>>, clearances: list<array<string, mixed>>,
     *     totals: array<string, int|float>, quotes: array{expo: list<array<string, mixed>>, impo: list<array<string, mixed>>, air: list<array<string, mixed>>}
     * }
     */
    public function __invoke(string $from, string $to, ?string $operation = null): array
    {
        $bookings = $this->bookings($from, $to, $operation, fn ($q) => $q->whereNotIn('e.tipo_embarque', ['s', 'd', 'c', 'r']));
        $insuranceBookings = $this->bookings($from, $to, $operation, fn ($q) => $q->where('e.tipo_embarque', 's'));
        $withInsuranceCharge = $this->bookings($from, $to, $operation, fn ($q) => $q->where('e.tipo_embarque', '<>', 's')
            ->whereIn('e.embarque_id', DB::table('fin_expenses_values')->whereIn('ref_expense', self::INSURANCE_EXPENSES)->where('selling_rate', '>', 0)->select('ref_embarque')));
        $clearances = $this->bookings($from, $to, $operation, fn ($q) => $q->where('e.tipo_embarque', 'd'));

        $goods = DB::table('mercadoria as m')
            ->join('tipo_container as t', 't.tipo_container_id', '=', 'm.tipo_container_id')
            ->whereIn('m.embarque_id', $bookings->pluck('embarque_id'))
            ->orderBy('m.mercadoria_id')
            ->get(['m.embarque_id', 'm.tipo_container_id', 'm.quantidade', 'm.gross_weight', 'm.cubature', 't.tipo', 't.nome'])
            ->groupBy('embarque_id');
        $starred = $this->starred($bookings->pluck('embarque_id'));

        $totals = ['fcl' => 0, 'containers' => 0, 'air' => 0, 'airKg' => 0.0, 'airM3' => 0.0, 'lcl' => 0, 'lclKg' => 0.0, 'lclM3' => 0.0, 'insurance' => 0, 'clearance' => 0];
        $rows = [];

        foreach ($bookings as $booking) {
            $items = $goods->get($booking->embarque_id, collect());

            if ($booking->tipo_embarque === 'a') {
                $totals['air']++;
            } elseif ($items->contains('tipo_container_id', self::LCL_CONTAINER)) {
                $totals['lcl']++;
            } else {
                $totals['fcl']++;
            }

            $containers = [];

            foreach ($items as $item) {
                $containers[] = $this->container($item, $booking->consolidado, $totals);
            }

            $rows[] = $this->row($booking) + [
                'star' => $starred->contains($booking->embarque_id),
                'containers' => implode(' + ', $containers),
                'manager' => $booking->de,
            ];
        }

        foreach ($withInsuranceCharge as $booking) {
            $rows[] = ['type' => 'Seguro'] + $this->row($booking) + ['star' => false, 'containers' => '', 'manager' => null];
            $totals['insurance']++;
        }

        $insurances = $insuranceBookings->map(fn ($booking) => ['type' => 'Seguro: '.$this->operationLabel($booking)] + $this->row($booking))->values()->all();
        $totals['insurance'] += count($insurances);
        $clearanceRows = $clearances->map(fn ($booking) => $this->row($booking))->values()->all();
        $totals['clearance'] = count($clearanceRows);

        return ['bookings' => $rows, 'insurances' => $insurances, 'clearances' => $clearanceRows, 'totals' => $totals, 'quotes' => $this->quotes($from, $to)];
    }

    /** @return Collection<int, object> */
    private function bookings(string $from, string $to, ?string $operation, Closure $constraint): Collection
    {
        return DB::table('embarque as e')
            ->leftJoin('pessoa as c', 'c.pessoa_id', '=', 'e.cliente_id')
            ->leftJoin('vendedor as v', 'v.vendedor_id', '=', 'e.vendedor_id')
            ->leftJoin('armador as a', 'a.armador_id', '=', 'e.armador_id')
            ->leftJoin('pessoa as ag', 'ag.pessoa_id', '=', 'e.pessoa_id_representante')
            ->leftJoin('porto as po', 'po.porto_id', '=', 'e.porto_origem_id')
            ->leftJoin('paises as pao', 'pao.paises_id', '=', 'po.ref_pais')
            ->leftJoin('porto as pd', 'pd.porto_id', '=', 'e.porto_destino_id')
            ->leftJoin('paises as pad', 'pad.paises_id', '=', 'pd.ref_pais')
            ->whereDate('e.dteCadastro', '>=', $from)
            ->whereDate('e.dteCadastro', '<=', $to)
            ->where('e.finalizado', '<>', 'c')
            ->when($operation, fn ($q) => $q->where('e.impor_expor', $operation))
            ->tap($constraint)
            ->orderBy('e.dteCadastro')
            ->orderBy('e.embarque_id')
            ->get([
                'e.embarque_id', 'e.dteCadastro', 'e.impor_expor', 'e.tipo_embarque', 'e.booking', 'e.consolidado', 'e.de',
                'c.nome as client', 'c.cnpj', 'v.nome as seller', 'a.nome as carrier', 'ag.nome as agent',
                'po.nome as origin', 'pao.nome as origin_country', 'pd.nome as destination', 'pad.nome as destination_country',
            ])
            ->values();
    }

    /** @return array<string, mixed> */
    private function row(object $booking): array
    {
        $port = fn (?string $name, ?string $country) => $name ? "{$name} ({$country})" : '';

        return [
            'id' => $booking->embarque_id,
            'date' => $booking->dteCadastro ? date('d/m/Y', strtotime($booking->dteCadastro)) : '',
            'type' => $this->operationLabel($booking),
            'operation' => $booking->impor_expor,
            'booking' => $booking->booking,
            'client' => (string) $booking->client,
            'seller' => $booking->seller ? strtok(trim($booking->seller), ' ') : '',
            'carrier' => (string) $booking->carrier,
            'agent' => (string) $booking->agent,
            'origin' => $port($booking->origin, $booking->origin_country),
            'destination' => $port($booking->destination, $booking->destination_country),
        ];
    }

    private function operationLabel(object $booking): string
    {
        return $booking->impor_expor === 'e' ? 'Exportação' : ($booking->impor_expor === 'i' ? 'Importação' : '');
    }

    /**
     * Texto de containers de um item e o acumulado nos totais: LCL e aéreo em kg ou m³ (o maior), o resto em quantidade × tipo.
     *
     * @param  array<string, int|float>  $totals
     */
    private function container(object $item, ?string $consolidated, array &$totals): string
    {
        $kg = number_format((float) $item->gross_weight, 2, ',', '.').' kgs';
        $m3 = number_format((float) $item->cubature, 2, ',', '.').' m3';

        if ($item->tipo === 'lcl') {
            if ($item->gross_weight / 1000 > $item->cubature) {
                $totals['lclKg'] += (float) $item->gross_weight;

                return $kg;
            }

            $totals['lclM3'] += (float) $item->cubature;

            return $m3;
        }

        if ($item->tipo === 'aereo') {
            if ($item->gross_weight > $item->cubature) {
                $totals['airKg'] += (float) $item->gross_weight;

                return $kg;
            }

            $totals['airM3'] += (float) $item->cubature;

            return $m3;
        }

        if ($consolidated === 's') {
            return 'Consolidado';
        }

        $totals['containers'] += (int) $item->quantidade;

        return "{$item->quantidade}*{$item->nome}";
    }

    /** Reservas com "Handling" (due to 7 na regra 7) ou "Taxa Administrativa". @return Collection<int, int> */
    private function starred(Collection $bookingIds): Collection
    {
        $handling = DB::table('fin_expenses_values as f')
            ->join('regra_despesa_cobranca_item as r', 'r.despesa_id', '=', 'f.ref_expense')
            ->where('r.regra_despesa_cobranca_id', 7)
            ->where('f.ref_due_to', 7)
            ->whereIn('f.ref_embarque', $bookingIds)
            ->pluck('f.ref_embarque');
        $fees = DB::table('fin_expenses_values')->whereIn('ref_expense', self::ADMIN_FEES)->whereIn('ref_embarque', $bookingIds)->pluck('ref_embarque');

        return $handling->merge($fees)->map(fn ($id) => (int) $id)->unique()->values();
    }

    /** @return array{expo: list<array<string, mixed>>, impo: list<array<string, mixed>>, air: list<array<string, mixed>>} */
    private function quotes(string $from, string $to): array
    {
        $period = fn ($query, string $table) => $query->whereDate("{$table}.created_at", '>=', $from)->whereDate("{$table}.created_at", '<=', $to)->orderBy("{$table}.created_at");

        $expo = $period(DB::table('cotacao_expo as q')
            ->leftJoin('pessoa as p', 'p.pessoa_id', '=', 'q.pessoa_id')
            ->leftJoin('armador as a', 'a.armador_id', '=', 'q.armador_id')
            ->leftJoin('produtos as pr', 'pr.id', '=', 'q.produto_id')
            ->leftJoin('porto as po', 'po.porto_id', '=', 'q.porto_origem_id')
            ->leftJoin('porto as pd', 'pd.porto_id', '=', 'q.porto_destino_id')
            ->leftJoin('usuario as u', 'u.usuario_id', '=', 'q.created_by'), 'q')
            ->selectRaw('q.cotacao_expo_id, q.created_at, q.transbordo, q.validade, p.nome as client, a.nome as carrier, pr.nome as product, po.nome as origin, pd.nome as destination, po.uncode as origin_code, pd.uncode as destination_code, u.nome as author')
            ->selectRaw('exists (select 1 from embarque where embarque.cotacao_expo_id = q.cotacao_expo_id) as booked')
            ->get();
        $types = DB::table('cotacao_expo_container as c')->join('tipo_container as t', 't.tipo_container_id', '=', 'c.tipo_container_id')->get(['c.cotacao_expo_id', 't.nome'])->groupBy('cotacao_expo_id');
        $typesFromItems = DB::table('cotacao_expo_item as i')->join('tipo_container as t', 't.tipo_container_id', '=', 'i.tipo_container_id')->get(['i.cotacao_expo_id', 't.nome'])->groupBy('cotacao_expo_id');

        $impo = $period(DB::table('cotacao_impo as q')
            ->leftJoin('pessoa as p', 'p.pessoa_id', '=', 'q.pessoa_id')
            ->leftJoin('pessoa as ag', 'ag.pessoa_id', '=', 'q.agente_id')
            ->leftJoin('armador as a', 'a.armador_id', '=', 'q.armador_id')
            ->leftJoin('porto as po', 'po.porto_id', '=', 'q.porto_origem_id')
            ->leftJoin('porto as pd', 'pd.porto_id', '=', 'q.porto_destino_id'), 'q')
            ->selectRaw('q.referencia, q.transbordo, p.nome as client, ag.nome as agent, a.nome as carrier, po.nome as origin, pd.nome as destination')
            ->selectRaw('exists (select 1 from embarque where embarque.cotacao_impo_id = q.cotacao_impo_id) as booked')
            ->get();

        $air = $period(DB::table('cotacao_impo_aereo as q')
            ->leftJoin('pessoa as p', 'p.pessoa_id', '=', 'q.pessoa_id')
            ->leftJoin('pessoa as ag', 'ag.pessoa_id', '=', 'q.agente_id')
            ->leftJoin('cia_aerea as ca', 'ca.id', '=', 'q.cia_aerea_id')
            ->leftJoin('porto as po', 'po.porto_id', '=', 'q.porto_origem_id')
            ->leftJoin('porto as pd', 'pd.porto_id', '=', 'q.porto_destino_id'), 'q')
            ->selectRaw('q.referencia, q.rota, p.nome as client, ag.nome as agent, ca.name as airline, po.nome as origin, pd.nome as destination')
            ->selectRaw('exists (select 1 from embarque where embarque.cotacao_impo_aereo_id = q.cotacao_impo_aereo_id) as booked')
            ->get();

        return [
            'expo' => $expo->map(fn ($row) => [
                'client' => (string) $row->client,
                'reference' => $this->exportReference($row),
                'carrier' => (string) $row->carrier,
                'product' => (string) $row->product,
                'equipment' => ($types->get($row->cotacao_expo_id) ?? $typesFromItems->get($row->cotacao_expo_id, collect()))->pluck('nome')->all(),
                'origin' => (string) $row->origin,
                'destination' => (string) $row->destination,
                'transshipment' => (string) $row->transbordo,
                'validity' => $row->validade ? date('d/m/Y', strtotime($row->validade)) : '',
                'booked' => (bool) $row->booked,
            ])->all(),
            'impo' => $impo->map(fn ($row) => ['client' => (string) $row->client, 'reference' => (string) $row->referencia, 'agent' => (string) $row->agent, 'carrier' => (string) $row->carrier, 'origin' => (string) $row->origin, 'destination' => (string) $row->destination, 'transshipment' => (string) $row->transbordo, 'booked' => (bool) $row->booked])->all(),
            'air' => $air->map(fn ($row) => ['client' => (string) $row->client, 'reference' => (string) $row->referencia, 'agent' => (string) $row->agent, 'airline' => (string) $row->airline, 'origin' => (string) $row->origin, 'destination' => (string) $row->destination, 'route' => (string) $row->rota, 'booked' => (bool) $row->booked])->all(),
        ];
    }

    /** CotacaoExpo::getReferencia (legado): iniciais do autor + código (4 dígitos) + UN/LOCODE de origem e destino + ano. */
    private function exportReference(object $row): string
    {
        $initials = collect(explode(' ', (string) $row->author))->filter(fn ($word) => $word !== '' && ! ctype_lower(mb_substr($word, 0, 1)))->map(fn ($word) => mb_substr($word, 0, 1))->implode('');

        return $initials.str_pad((string) $row->cotacao_expo_id, 4, '0', STR_PAD_LEFT).substr((string) $row->origin_code, 2).substr((string) $row->destination_code, 2).'/'.date('Y', strtotime((string) $row->created_at));
    }
}
