<?php

namespace App\Actions\Reports;

use App\Services\Cobranca\ChargeDetails;
use Illuminate\Support\Facades\DB;

/**
 * reports/listaProcessoSemNotas (legado), "Pendências Debit/Credit Notes": reservas marítimas de exportação embarcadas desde 2020,
 * sem nota de débito nem de crédito (nem controle de nota dispensada), com as despesas de frete negociado de cada uma.
 *
 * A tela lista tudo até hoje; o PDF só as embarcadas há mais de 5 dias (`$until`).
 */
class PendingNotesReport
{
    private const SINCE = '2020-01-01';

    private const FREIGHT_RULE = 4;

    /** Frete negociado: as despesas da regra 4 e o handling DMF. */
    private const HANDLING_EXPENSES = [703, 1612, 1613];

    private const BILLING = ['a' => 'PA', 'c' => 'CC', 'p' => 'PP', 's' => 'SEG', 'f' => 'FI'];

    public function __construct(private ChargeDetails $details) {}

    /** @return list<array{id: int, booking: string, direct_bl: string, freight: list<string>, shipper: string, vessel: string, origin: string, destination: string, departed: string}> */
    public function __invoke(?string $until = null): array
    {
        $bookings = DB::table('embarque as e')
            ->leftJoin('pessoa as p', 'p.pessoa_id', '=', 'e.pessoa_id_cliente')
            ->leftJoin('navio as n', 'n.navio_id', '=', 'e.navio_id')
            ->leftJoin('porto as po', 'po.porto_id', '=', 'e.porto_origem_id')
            ->leftJoin('porto as pd', 'pd.porto_id', '=', 'e.porto_destino_id')
            ->leftJoin('paises as pao', 'pao.paises_id', '=', 'po.ref_pais')
            ->leftJoin('paises as pad', 'pad.paises_id', '=', 'pd.ref_pais')
            ->where('e.saida_efetiva', '>=', self::SINCE)
            ->when($until, fn ($query) => $query->where('e.saida_efetiva', '<=', $until))
            ->where('e.tipo_embarque', 'm')
            ->where('e.impor_expor', 'e')
            ->whereNull('e.controle_credit')
            ->whereNull('e.controle_debit')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('fin_credit_notes as c')->whereColumn('c.ref_embarque', 'e.embarque_id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('fin_debit_notes as d')->whereColumn('d.ref_embarque', 'e.embarque_id'))
            ->orderBy('e.saida_efetiva')
            ->orderBy('e.embarque_id')
            ->get(['e.embarque_id', 'e.booking', 'e.bl_direto', 'e.saida_efetiva', 'p.nome as shipper', 'n.nome as vessel', 'po.nome as origin', 'pao.nome as origin_country', 'pd.nome as destination', 'pad.nome as destination_country']);

        $freight = $this->freight($bookings->pluck('embarque_id')->all());

        return $bookings->map(fn ($row) => [
            'id' => (int) $row->embarque_id,
            'booking' => (string) $row->booking,
            'direct_bl' => $row->bl_direto ? 'SIM' : 'NÃO',
            'freight' => $freight[$row->embarque_id] ?? [],
            'shipper' => (string) $row->shipper,
            'vessel' => (string) $row->vessel,
            'origin' => $this->port($row->origin, $row->origin_country),
            'destination' => $this->port($row->destination, $row->destination_country),
            'departed' => substr((string) $row->saida_efetiva, 0, 10),
        ])->all();
    }

    /**
     * @param  list<int>  $bookingIds
     * @return array<int, list<string>> reserva => "despesa/unidade (equipamento) (BL) container - faturamento"
     */
    private function freight(array $bookingIds): array
    {
        if ($bookingIds === []) {
            return [];
        }

        $expenseIds = [...DB::table('regra_despesa_cobranca_item')->where('regra_despesa_cobranca_id', self::FREIGHT_RULE)->pluck('despesa_id')->all(), ...self::HANDLING_EXPENSES];
        $freight = [];

        $rows = DB::table('fin_expenses_values as v')
            ->join('fin_expenses as e', 'e.id', '=', 'v.ref_expense')
            ->leftJoin('fin_expenses_unities as u', 'u.id', '=', 'e.ref_unity')
            ->whereIn('v.ref_embarque', $bookingIds)
            ->whereIn('v.ref_expense', $expenseIds)
            ->orderBy('v.id')
            ->get(['v.ref_embarque', 'v.billing', 'v.ref_equipment', 'v.ref_bl as v_bl', 'v.ref_container', 'e.name as expense_name', 'u.acronym as unity_name']);

        foreach ($rows as $row) {
            $freight[$row->ref_embarque][] = $this->details->expenseLabel($row).' - '.(self::BILLING[$row->billing] ?? '');
        }

        return $freight;
    }

    /** Embarque::getPortoOrigem: "Porto (País)". */
    private function port(?string $name, ?string $country): string
    {
        return $name === null ? '' : "{$name} ({$country})";
    }
}
