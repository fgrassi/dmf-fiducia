<?php

namespace App\Actions\Reports;

use Illuminate\Support\Facades\DB;

/**
 * relTaxasNegociadas (legado), "Taxas Negociadas": cotações de moeda negociadas à mão em cobranças, pela data do
 * lançamento, com a reserva, o cliente e quem cadastrou. Diferença do legado: o dia final entra inteiro (o legado cortava
 * em 00:00 e perdia os lançamentos do último dia).
 */
class NegotiatedRatesReport
{
    /** @return list<array<string, mixed>> */
    public function __invoke(?string $from, ?string $to, ?string $operation = null): array
    {
        return DB::table('fin_custom_currency_rate as r')
            ->leftJoin('fin_charges as c', 'c.id', '=', 'r.ref_charge')
            ->leftJoin('embarque as e', 'e.embarque_id', '=', 'c.ref_booking')
            ->leftJoin('pessoa as p', 'p.pessoa_id', '=', 'e.cliente_id')
            ->leftJoin('cmn_currencies as m', 'm.id', '=', 'r.ref_currency')
            ->leftJoin('usuario as u', 'u.usuario_id', '=', 'r.created_by')
            ->when($from, fn ($q) => $q->whereDate('r.created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('r.created_at', '<=', $to))
            ->when($operation, fn ($q) => $q->where('e.impor_expor', $operation))
            ->orderBy('r.created_at')
            ->orderBy('r.id')
            ->get(['r.id', 'r.created_at', 'r.custom_rate', 'r.note', 'e.impor_expor', 'e.booking', 'c.total_value', 'p.nome as client', 'p.cnpj', 'm.name as currency', 'm.symbol', 'u.nome as author'])
            ->map(fn ($row) => [
                'id' => $row->id,
                'date' => $row->created_at,
                'operation' => $row->impor_expor === 'e' ? 'Exportação' : ($row->impor_expor === 'i' ? 'Importação' : ''),
                'booking' => $row->booking,
                'total' => (float) $row->total_value,
                'client' => $row->client ? "{$row->client} ({$row->cnpj})" : '',
                'currency' => $row->currency ? "{$row->currency} ({$row->symbol})" : '',
                'rate' => (float) $row->custom_rate,
                'author' => $row->author,
                'note' => $row->note,
            ])
            ->all();
    }
}
