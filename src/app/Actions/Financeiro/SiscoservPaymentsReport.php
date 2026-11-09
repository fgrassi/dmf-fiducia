<?php

namespace App\Actions\Financeiro;

use App\Enums\Cobranca\ChargeStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * pagamentoSiscoserv/index (legado), "Relatório Pagamentos - SISCOSERV": cobranças pagas de reservas marítimas de importação,
 * filtradas pela data de pagamento e pelo cliente da reserva. Sem nenhum filtro o legado não listava nada.
 */
class SiscoservPaymentsReport
{
    /**
     * @param  array{data_ini?: ?string, data_fim?: ?string, cliente_id?: int|string|null}  $filters
     * @return Collection<int, object{cliente: ?string, agente: ?string, reserva: ?string, escritorio: ?string}>
     */
    public function __invoke(array $filters): Collection
    {
        $from = $filters['data_ini'] ?? null;
        $to = $filters['data_fim'] ?? null;
        $client = $filters['cliente_id'] ?? null;

        if (! $from && ! $to && ! $client) {
            return collect();
        }

        return DB::table('fin_charges as fc')
            ->join('embarque as q', 'q.embarque_id', '=', 'fc.ref_booking')
            ->leftJoin('pessoa as cliente', 'cliente.pessoa_id', '=', 'q.cliente_id')
            ->leftJoin('pessoa as agente', 'agente.pessoa_id', '=', 'q.pessoa_id_representante')
            ->leftJoin('escritorio as e', 'e.escritorio_id', '=', 'fc.escritorio_id')
            ->where('fc.status', ChargeStatus::Paid->value)
            ->where('q.tipo_embarque', 'm')
            ->where('q.impor_expor', 'i')
            ->when($from, fn ($query) => $query->whereDate('fc.payment_date', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('fc.payment_date', '<=', $to))
            ->when($client, fn ($query) => $query->where('q.cliente_id', $client))
            ->orderBy('fc.id')
            ->get(['cliente.nome as cliente', 'agente.nome as agente', 'q.booking as reserva', 'e.nome as escritorio']);
    }
}
