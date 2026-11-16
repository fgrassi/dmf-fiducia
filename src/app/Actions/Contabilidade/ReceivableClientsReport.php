<?php

namespace App\Actions\Contabilidade;

use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * contabilidade/clientesReceber (legado), "Clientes a Receber": cobranças com NFS-e autorizada emitida no período que ainda tinham
 * saldo a receber na data final (total − desconto − recebido até essa data).
 */
class ReceivableClientsReport
{
    /**
     * @param  array{data_ini?: ?string, data_fim?: ?string}  $input
     * @return array{rows: list<array<string, mixed>>, totals: array<string, float>}
     *
     * @throws DomainException período inválido
     */
    public function __invoke(array $input): array
    {
        [$start, $end] = ReportPeriod::parse($input);

        $query = DB::table('fin_charges as fc')
            ->join('embarque as e', 'e.embarque_id', '=', 'fc.ref_booking')
            ->join('fin_charges_nfse as n', fn ($join) => $join->on('n.ref_charge', '=', 'fc.id')->where('n.status', 'autorizada'))
            ->leftJoin('pessoa as p', 'p.pessoa_id', '=', 'e.cliente_id');

        $charges = [];

        foreach (ChargeBalance::candidates($query, $end)->get([
            'fc.id as charge_id', 'fc.num_nota_fiscal', 'fc.total_value', 'fc.discount', 'fc.received_value', 'fc.payment_date', 'fc.due_date', 'fc.status',
            'e.booking', 'e.data_topo', 'p.nome as cliente', 'n.chave_acesso', 'n.payload_json', 'n.created_at as nfse_created_at',
        ]) as $row) {
            $issued = NfseInvoice::issueDate($row->payload_json, $row->nfse_created_at);

            if ($issued === null || $issued < $start || $issued > $end || $issued <= ($charges[$row->charge_id]['nf_data'] ?? '')) {
                continue;
            }

            $charges[$row->charge_id] = [
                'cliente' => filled(trim((string) $row->cliente)) ? trim($row->cliente) : '(sem cliente)',
                'reserva_data' => NfseInvoice::date($row->data_topo),
                'reserva_numero' => trim((string) $row->booking),
                'nf_data' => $issued,
                'nf_numero' => NfseInvoice::number($row->chave_acesso, $row->num_nota_fiscal),
                'valor' => ChargeBalance::openAt($row, $end),
            ];
        }

        $rows = collect($charges)
            ->filter(fn (array $charge) => $charge['valor'] > 0.01)
            ->sort(fn (array $a, array $b) => strcasecmp($a['cliente'], $b['cliente']) ?: strcmp($a['reserva_numero'], $b['reserva_numero']) ?: strcmp($a['nf_data'], $b['nf_data']) ?: strcmp($a['nf_numero'], $b['nf_numero']))
            ->values();

        return ['rows' => $rows->all(), 'totals' => ['valor' => round($rows->sum('valor'), 2)]];
    }
}
