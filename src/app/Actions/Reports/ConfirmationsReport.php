<?php

namespace App\Actions\Reports;

use Illuminate\Support\Facades\DB;

/**
 * relConfirmacaoChegada / relConfirmacaoEmbarque (legado): confirmações de chegada ou de embarque registradas nas
 * reservas cuja chegada prevista cai no período (nos dois relatórios, como o legado), com quem confirmou.
 * Diferença do legado: o dia final entra inteiro e o mês anterior em janeiro deixa de gerar data inválida.
 */
class ConfirmationsReport
{
    public const TABLES = ['arrival' => 'arrival_confirmation', 'departure' => 'departure_confirmation'];

    /** @return list<array<string, mixed>> */
    public function __invoke(string $kind, ?string $from, ?string $to, ?string $operation = null): array
    {
        return DB::table(self::TABLES[$kind].' as k')
            ->join('embarque as e', 'e.embarque_id', '=', 'k.ref_embarque')
            ->leftJoin('pessoa as p', 'p.pessoa_id', '=', 'e.cliente_id')
            ->leftJoin('usuario as u', 'u.usuario_id', '=', 'k.created_by')
            ->when($from, fn ($q) => $q->whereDate('e.chegada_prevista', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('e.chegada_prevista', '<=', $to))
            ->when($operation, fn ($q) => $q->where('e.impor_expor', $operation))
            ->orderBy('k.created_at')
            ->orderBy('k.id')
            ->get(['k.created_at', 'e.embarque_id', 'e.impor_expor', 'e.booking', 'e.saida_prevista', 'e.chegada_prevista', 'p.nome as client', 'u.nome as author'])
            ->map(fn ($row) => [
                'id' => $row->embarque_id,
                'date' => $row->created_at,
                'operation' => $row->impor_expor,
                'booking' => $row->booking,
                'client' => $row->client,
                'departure' => $row->saida_prevista,
                'arrival' => $row->chegada_prevista,
                'author' => $row->author,
            ])
            ->all();
    }
}
