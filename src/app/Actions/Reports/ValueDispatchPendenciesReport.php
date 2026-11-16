<?php

namespace App\Actions\Reports;

use Illuminate\Support\Facades\DB;

/**
 * relPendenciaEnvioVal/list (legado), "Pendências Envio de Valores ao Despachante": as reservas de clientes com "controlar envio de
 * valores finais ao despachante", pendentes (1) ou liberadas (2), com quem liberou e quando.
 */
class ValueDispatchPendenciesReport
{
    public const PENDING = 1;

    public const RELEASED = 2;

    /**
     * @param  array{status?: int|string|null, embarque_id?: int|string|null, data?: ?string}  $filters  data = dia da liberação
     * @return list<array{id: int, booking: string, client: string, status: string, released_by: string, released_on: ?string}>
     */
    public function __invoke(array $filters = []): array
    {
        return DB::table('controle_envio_valores as c')
            ->join('embarque as e', 'e.embarque_id', '=', 'c.embarque_id')
            ->leftJoin('pessoa as p', 'p.pessoa_id', '=', 'e.cliente_id')
            ->leftJoin('usuario as u', 'u.usuario_id', '=', 'c.updated_by')
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('c.status', $status))
            ->when($filters['embarque_id'] ?? null, fn ($query, $id) => $query->where('c.embarque_id', $id))
            ->when($filters['data'] ?? null, fn ($query, $date) => $query->where('c.updated_at', $date))
            ->orderByDesc('c.controle_envio_valores_id')
            ->get(['c.controle_envio_valores_id', 'c.embarque_id', 'c.status', 'c.updated_at', 'e.booking', 'p.nome as client', 'u.nome as released_by'])
            ->map(fn ($row) => [
                'id' => (int) $row->embarque_id,
                'booking' => (string) $row->booking,
                'client' => (string) $row->client,
                'status' => (int) $row->status === self::PENDING ? 'Pendente' : 'Liberado',
                'released_by' => (string) $row->released_by,
                'released_on' => filled($row->updated_at) ? substr($row->updated_at, 0, 10) : null,
            ])
            ->all();
    }
}
