<?php

namespace App\Actions\Reports;

use Illuminate\Support\Facades\DB;

/**
 * relTaxasLocais (legado), "Taxas Locais: Armador - Porto": despesas da matriz sem vencimento (`due_to` vazio) por armador e
 * porto, com equipamento e valor, opcionalmente do tipo de reserva, armador ou porto escolhido.
 *
 * Diferença do legado: filtrar só pelo tipo também lista (o legado devolvia vazio nessa combinação).
 */
class LocalChargesReport
{
    /**
     * @return list<array{carrier: string, ports: list<array{port: string, charges: list<array{expense: string, equipment: string, value: float, symbol: string}>}>}>
     */
    public function __invoke(?string $type, ?int $carrierId, ?int $portId): array
    {
        $rows = DB::table('fin_expenses_matrix as m')
            ->join('cmn_armador_porto as ap', 'ap.id', '=', 'm.ref_armador_porto')
            ->join('armador as a', 'a.armador_id', '=', 'ap.ref_armador')
            ->join('porto as p', 'p.porto_id', '=', 'ap.ref_porto')
            ->join('fin_expenses as e', 'e.id', '=', 'm.ref_expense')
            ->leftJoin('fin_expenses_unities as u', 'u.id', '=', 'e.ref_unity')
            ->leftJoin('tipo_container as t', 't.tipo_container_id', '=', 'm.ref_equipamento')
            ->leftJoin('cmn_currencies as c', 'c.id', '=', 'm.ref_currency')
            ->whereNull('m.due_to')
            ->when($type, fn ($q) => $q->where('m.type', $type))
            ->when($carrierId, fn ($q) => $q->where('ap.ref_armador', $carrierId))
            ->when($portId, fn ($q) => $q->where('p.porto_id', $portId))
            ->orderBy('a.nome')
            ->orderBy('p.nome')
            ->orderBy('e.name')
            ->orderBy('m.id')
            ->get(['a.armador_id', 'a.nome as carrier', 'p.porto_id', 'p.nome as port', 'e.name', 'u.acronym', 't.nome as equipment', 'm.expense_value', 'c.symbol']);

        return $rows->groupBy('armador_id')->map(fn ($byCarrier) => [
            'carrier' => $byCarrier->first()->carrier,
            'ports' => $byCarrier->groupBy('porto_id')->map(fn ($byPort) => [
                'port' => $byPort->first()->port,
                'charges' => $byPort->map(fn ($row) => [
                    'expense' => $row->name.($row->acronym ? " / {$row->acronym}" : ''),
                    'equipment' => (string) $row->equipment,
                    'value' => (float) $row->expense_value,
                    'symbol' => (string) $row->symbol,
                ])->values()->all(),
            ])->values()->all(),
        ])->values()->all();
    }
}
