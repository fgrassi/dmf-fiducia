<?php

namespace App\Actions\Contabilidade;

use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * contabilidade/notasEmitidasMes (legado), "Custos das Notas Emitidas no mês": NFS-e autorizadas emitidas no período, com o custo de
 * compra das despesas da cobrança em USD, euro e R$ (pagas ou não).
 */
class IssuedInvoiceCostsReport
{
    private const CHUNK = 400;

    /**
     * @param  array{data_ini?: ?string, data_fim?: ?string}  $input
     * @return array{rows: list<array<string, mixed>>, totals: array<string, float>}
     *
     * @throws DomainException período inválido
     */
    public function __invoke(array $input): array
    {
        [$start, $end] = ReportPeriod::parse($input);

        $invoices = $this->invoices($start, $end);
        $costs = $this->costs(array_keys($invoices));

        $rows = collect($invoices)
            ->map(fn (array $invoice, int $chargeId) => $invoice + [
                'usd' => round($costs[$chargeId]['usd'], 2),
                'eur' => round($costs[$chargeId]['eur'], 2),
                'brl' => round($costs[$chargeId]['brl'], 2),
            ])
            ->sort(fn (array $a, array $b) => strcmp($a['nf_data'], $b['nf_data']) ?: strcmp($a['nf_numero'], $b['nf_numero']) ?: strcmp($a['reserva_numero'], $b['reserva_numero']))
            ->values();

        return ['rows' => $rows->all(), 'totals' => [
            'usd' => round($rows->sum('usd'), 2),
            'eur' => round($rows->sum('eur'), 2),
            'brl' => round($rows->sum('brl'), 2),
        ]];
    }

    /** @return array<int, array<string, mixed>> cobrança => a NFS-e emitida mais tarde no período */
    private function invoices(string $start, string $end): array
    {
        // A data de emissão está no payload (pode diferir do created_at em até um dia ou dois): busca com folga e filtra depois.
        $rows = DB::table('fin_charges as fc')
            ->join('embarque as e', 'e.embarque_id', '=', 'fc.ref_booking')
            ->join('fin_charges_nfse as n', fn ($join) => $join->on('n.ref_charge', '=', 'fc.id')->where('n.status', 'autorizada'))
            ->leftJoin('pessoa as p', 'p.pessoa_id', '=', 'e.cliente_id')
            ->where('n.created_at', '>=', Carbon::parse($start)->subDay()->toDateString())
            ->where('n.created_at', '<', Carbon::parse($end)->addDays(2)->toDateString())
            ->get(['fc.id as charge_id', 'fc.num_nota_fiscal', 'e.booking', 'p.nome as cliente', 'n.chave_acesso', 'n.payload_json', 'n.created_at as nfse_created_at']);

        $invoices = [];

        foreach ($rows as $row) {
            $issued = NfseInvoice::issueDate($row->payload_json, $row->nfse_created_at);

            if ($issued === null || $issued < $start || $issued > $end || $issued <= ($invoices[$row->charge_id]['nf_data'] ?? '')) {
                continue;
            }

            $invoices[$row->charge_id] = [
                'nf_data' => $issued,
                'nf_numero' => NfseInvoice::number($row->chave_acesso, $row->num_nota_fiscal),
                'reserva_numero' => trim((string) $row->booking),
                'cliente' => filled(trim((string) $row->cliente)) ? trim($row->cliente) : '(sem cliente)',
            ];
        }

        return $invoices;
    }

    /**
     * @param  list<int>  $chargeIds
     * @return array<int, array{usd: float, eur: float, brl: float}>
     */
    private function costs(array $chargeIds): array
    {
        $costs = array_fill_keys($chargeIds, ['usd' => 0.0, 'eur' => 0.0, 'brl' => 0.0]);

        foreach (array_chunk($chargeIds, self::CHUNK) as $chunk) {
            $rows = DB::table('fin_charges_items as fci')
                ->join('fin_charges as fc', 'fc.id', '=', 'fci.ref_charge')
                ->join('embarque as e', 'e.embarque_id', '=', 'fc.ref_booking')
                ->join('fin_expenses_values as fev', 'fev.id', '=', 'fci.ref_expense')
                ->join('fin_expenses as fe', 'fe.id', '=', 'fev.ref_expense')
                ->leftJoin('cmn_currencies as c', 'c.id', '=', 'fev.ref_currency_br')
                ->whereIn('fci.ref_charge', $chunk)
                ->whereRaw('COALESCE(fev.buying_rate, 0) > 0')
                ->selectRaw('fci.ref_charge as charge_id, fev.buying_rate, fev.br_min, c.symbol, c.name, '.BuyingCost::FACTOR_SQL.' as fator')
                ->get();

            foreach ($rows as $row) {
                if ($bucket = $this->currency($row->symbol, $row->name)) {
                    $costs[$row->charge_id][$bucket] += BuyingCost::value($row);
                }
            }
        }

        return $costs;
    }

    /** @return 'usd'|'eur'|'brl'|null */
    private function currency(?string $symbol, ?string $name): ?string
    {
        $symbol = strtoupper(trim((string) $symbol));
        $text = $symbol.' '.strtoupper(trim((string) $name));

        return match (true) {
            str_contains($text, 'EUR') => 'eur',
            str_contains($text, 'USD'), str_contains($text, 'DOLAR'), str_contains($symbol, 'US$'), $symbol === 'U$' => 'usd',
            str_contains($text, 'BRL'), str_contains($text, 'REAL'), str_contains($symbol, 'R$') => 'brl',
            default => null,
        };
    }
}
