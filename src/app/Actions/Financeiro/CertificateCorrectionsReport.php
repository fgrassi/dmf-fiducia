<?php

namespace App\Actions\Financeiro;

use Illuminate\Support\Facades\DB;

/**
 * relatorioDespesasReceitas (legado), "Arrecadação Certificados e Correções": linhas da view
 * `vw_resultado_certificado_correcoes` (despesas dos grupos 1 e 2 com compra ≠ venda, por ETD no EXPO e ETA no IMPO) no
 * período da data da despesa. Despesa = soma das ordens de pagamento (armador e outros) da despesa; receita = soma dos itens
 * de cobranças não canceladas; resultado = receita − despesa, tudo em R$.
 */
class CertificateCorrectionsReport
{
    /**
     * @param  array{data_ini?: ?string, data_fim?: ?string}  $input
     * @return array{rows: list<array<string, mixed>>, totais: array{despesa: float, receita: float, resultado: float}}
     */
    public function __invoke(array $input): array
    {
        $rows = DB::table('vw_resultado_certificado_correcoes as v')
            ->when(filled($input['data_ini'] ?? null), fn ($query) => $query->where('v.data_despesa', '>=', $input['data_ini']))
            ->when(filled($input['data_fim'] ?? null), fn ($query) => $query->where('v.data_despesa', '<=', $input['data_fim']))
            ->orderBy('v.data_despesa')
            ->orderBy('v.booking')
            ->get(['v.booking', 'v.nome_despesa', 'v.despesa_id', 'v.despesa', 'v.receita', 'v.data_despesa', 'v.moeda_venda', 'v.moeda_compra'])
            ->map(fn ($row) => (array) $row);

        $ids = $rows->pluck('despesa_id')->unique()->values();
        $revenues = $this->revenues($ids);
        $costs = $this->costs($ids);

        $rows = $rows->map(function (array $row) use ($revenues, $costs) {
            $row['despesa_rs'] = $costs[$row['despesa_id']] ?? 0.0;
            $row['receita_rs'] = $revenues[$row['despesa_id']] ?? 0.0;
            $row['resultado'] = $row['receita_rs'] - $row['despesa_rs'];

            return $row;
        });

        return [
            'rows' => $rows->all(),
            'totais' => [
                'despesa' => $rows->sum('despesa_rs'),
                'receita' => $rows->sum('receita_rs'),
                'resultado' => $rows->sum('resultado'),
            ],
        ];
    }

    /** @return array<int, float> receita por despesa (itens de cobranças não canceladas) */
    private function revenues($ids): array
    {
        $totals = [];

        foreach ($ids->chunk(500) as $chunk) {
            $totals += DB::table('fin_charges_items as i')
                ->join('fin_charges as c', 'c.id', '=', 'i.ref_charge')
                ->where('c.status', '<>', 0)
                ->whereIn('i.ref_expense', $chunk)
                ->groupBy('i.ref_expense')
                ->selectRaw('i.ref_expense as id, SUM(i.item_value) as total')
                ->pluck('total', 'id')
                ->map(fn ($total) => (float) $total)
                ->all();
        }

        return $totals;
    }

    /** @return array<int, float> despesa por despesa: ordens de pagamento do armador mais as de outros, sem filtro de status (como o legado) */
    private function costs($ids): array
    {
        $totals = [];

        foreach ($ids->chunk(500) as $chunk) {
            foreach ([['fin_payment_order_items', 'ref_payment_order', 'fin_payment_orders', 'id'], ['ordem_pagamento_item', 'ordem_pagamento_id', 'ordem_pagamento', 'ordem_pagamento_id']] as [$items, $itemKey, $orders, $orderKey]) {
                $sums = DB::table("{$items} as i")
                    ->join("{$orders} as o", "o.{$orderKey}", '=', "i.{$itemKey}")
                    ->whereIn('i.ref_expense', $chunk)
                    ->groupBy('i.ref_expense')
                    ->selectRaw('i.ref_expense as id, SUM(i.item_value) as total')
                    ->pluck('total', 'id');

                foreach ($sums as $id => $total) {
                    $totals[$id] = ($totals[$id] ?? 0.0) + (float) $total;
                }
            }
        }

        return $totals;
    }
}
