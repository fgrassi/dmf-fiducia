<?php

namespace App\Actions\Financeiro;

use Illuminate\Support\Facades\DB;

/**
 * relatorioIof (legado), "Arrecadação IOF": cobranças não canceladas com IOF, pagas no período, com reserva, HBL e valor do IOF.
 */
class IofRevenueReport
{
    /**
     * @param  array{data_ini?: ?string, data_fim?: ?string}  $input
     * @return array{rows: list<array<string, mixed>>, total: float}
     */
    public function __invoke(array $input): array
    {
        $rows = DB::table('fin_charges as c')
            ->leftJoin('embarque as e', 'e.embarque_id', '=', 'c.ref_booking')
            ->leftJoin('bl as b', 'b.bl_id', '=', 'c.ref_bl')
            ->whereBetween('c.payment_date', [$input['data_ini'] ?? '', $input['data_fim'] ?? ''])
            ->where('c.status', '<>', 0)
            ->where('c.valor_iof', '>', 0)
            ->orderBy('c.id')
            ->get(['e.booking', 'b.bl', 'c.valor_iof', 'c.payment_date'])
            ->map(fn ($row) => (array) $row);

        return ['rows' => $rows->all(), 'total' => (float) $rows->sum('valor_iof')];
    }
}
