<?php

namespace App\Actions\Comissoes;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Relatório Comissões de Embarque (legado `comissoes/list` + `comissoes/show`): comissões por reserva e por comissionado,
 * com o que já foi pago em R$. Lista só leitura; o lançamento de pagamento e a troca de tipo ficam de fora.
 *
 * Regras do legado mantidas: o pago em R$ soma o valor direto quando a moeda é real (id 1) e valor × taxa nas outras
 * (pagamento sem taxa fica de fora); a taxa exibida é a do último pagamento que tem taxa; a data do recibo é a das
 * cobranças da reserva com status diferente de 0; a ordem é por data do recibo (reserva sem recibo conta como a mais
 * recente) e, no empate, a comissão mais nova primeiro.
 *
 * @phpstan-type Filters array{responsavel_id?:int|string|null, booking?:?string, tipo_comissao?:int|string|null, tipo?:int|string|null, status?:int|string|null, criada_desde?:?string, dir?:?string}
 */
class ShippingCommissionsReport
{
    private const REAL = 1;

    public const COMMISSION_TYPES = [0 => 'Processo', 1 => 'Container', 2 => 'BL'];

    public const DIRECTIONS = [0 => 'Pagar', 1 => 'Receber'];

    public const STATUSES = [0 => 'Aberto', 1 => 'Pago'];

    /**
     * @param  Filters  $filters
     * @return array{rows: list<array<string, mixed>>, totais: array{containers:int, pago:float}}
     */
    public function __invoke(array $filters = []): array
    {
        $commissions = $this->query($filters)->get();
        $ids = $commissions->pluck('comissao_embarque_id')->all();
        $bookings = $commissions->pluck('embarque_id')->unique()->all();

        $containers = DB::table('container as ct')
            ->join('bl', 'bl.bl_id', '=', 'ct.bl_id')
            ->whereIn('bl.embarque_id', $bookings)
            ->groupBy('bl.embarque_id')
            ->selectRaw('bl.embarque_id, count(*) as total')
            ->pluck('total', 'embarque_id');

        $receipts = DB::table('fin_charges')
            ->whereIn('ref_booking', $bookings)
            ->where('status', '<>', 0)
            ->whereNotNull('payment_date')
            ->orderBy('id')
            ->get(['ref_booking', 'payment_date'])
            ->groupBy('ref_booking');

        $payments = DB::table('comissao_embarque_pagamento')
            ->whereIn('comissao_embarque_id', $ids)
            ->orderBy('comissao_embarque_pagamento_id')
            ->get()
            ->groupBy('comissao_embarque_id');

        $rows = $commissions->map(function (object $c) use ($containers, $receipts, $payments) {
            $own = $payments->get($c->comissao_embarque_id, collect());
            $dates = $receipts->get($c->embarque_id, collect())->pluck('payment_date')->map(fn ($d) => substr((string) $d, 0, 10));

            return [
                'id' => (int) $c->comissao_embarque_id,
                'responsavel' => (string) $c->responsavel,
                'booking' => (string) $c->booking,
                'tipo' => self::DIRECTIONS[(int) $c->tipo] ?? '',
                'tipo_valor' => (int) $c->tipo,
                'tipo_comissao' => self::COMMISSION_TYPES[(int) $c->tipo_comissao] ?? '',
                'tipo_comissao_valor' => (int) $c->tipo_comissao,
                'moeda' => $c->moeda_nome ? "{$c->moeda_nome} ({$c->moeda_simbolo})" : '',
                'containers' => (int) ($containers[$c->embarque_id] ?? 0),
                'valor_unitario' => (float) $c->valor_unitario,
                'pago' => $this->paidInReais((int) $c->moeda_id, $own),
                'taxa' => $own->pluck('taxa')->filter()->last(),
                'datas_recibo' => $dates->all(),
                'data_recibo' => $dates->max(),
                'status' => self::STATUSES[(int) $c->status] ?? 'Aberto',
                'obs' => (string) $c->obs,
                'created_at' => (string) $c->created_at,
            ];
        })->all();

        usort($rows, $this->comparator(($filters['dir'] ?? 'desc') === 'asc' ? 1 : -1));

        return [
            'rows' => $rows,
            'totais' => [
                'containers' => array_sum(array_column($rows, 'containers')),
                'pago' => array_sum(array_column($rows, 'pago')),
            ],
        ];
    }

    /** @param  Filters  $filters */
    private function query(array $filters): Builder
    {
        $query = DB::table('comissao_embarque as c')
            ->join('comissao_embarque_responsavel as r', 'r.comissao_embarque_responsavel_id', '=', 'c.comissao_embarque_responsavel_id')
            ->join('embarque as e', 'e.embarque_id', '=', 'c.embarque_id')
            ->leftJoin('cmn_currencies as m', 'm.id', '=', 'c.moeda_id')
            ->select('c.*', 'r.nome as responsavel', 'e.booking', 'm.name as moeda_nome', 'm.symbol as moeda_simbolo');

        $query->when(filled($filters['responsavel_id'] ?? null), fn ($q) => $q->where('c.comissao_embarque_responsavel_id', (int) $filters['responsavel_id']));
        $query->when(filled($filters['criada_desde'] ?? null), fn ($q) => $q->where('c.created_at', '>=', $filters['criada_desde']));
        $query->when(filled(trim((string) ($filters['booking'] ?? ''))), fn ($q) => $q->where('e.booking', 'like', '%'.trim($filters['booking']).'%'));
        $query->when($this->isOption($filters['tipo_comissao'] ?? null, self::COMMISSION_TYPES), fn ($q) => $q->where('c.tipo_comissao', (int) $filters['tipo_comissao']));
        $query->when($this->isOption($filters['tipo'] ?? null, self::DIRECTIONS), fn ($q) => $q->where('c.tipo', (int) $filters['tipo']));
        $query->when(($filters['status'] ?? null) !== null && ($filters['status'] ?? '') !== '', fn ($q) => (int) $filters['status'] === 1
            ? $q->where('c.status', 1)
            : $q->where(fn ($w) => $w->whereNull('c.status')->orWhere('c.status', 0)));

        return $query;
    }

    /** @param  array<int, string>  $options */
    private function isOption(mixed $value, array $options): bool
    {
        return in_array((string) $value, array_map('strval', array_keys($options)), true);
    }

    /** @param  iterable<object>  $payments */
    private function paidInReais(int $currencyId, iterable $payments): float
    {
        $total = 0.0;

        foreach ($payments as $payment) {
            if ($currencyId === self::REAL) {
                $total += (float) $payment->valor_total;
            } elseif ($payment->taxa) {
                $total += (float) $payment->valor_total * (float) $payment->taxa;
            }
        }

        return $total;
    }

    /** Sem recibo conta como a mais recente (topo na ordem decrescente); empate: criação mais nova, depois id maior. */
    private function comparator(int $sign): callable
    {
        return function (array $a, array $b) use ($sign): int {
            if ($a['data_recibo'] !== $b['data_recibo']) {
                return match (true) {
                    $a['data_recibo'] === null => $sign,
                    $b['data_recibo'] === null => -$sign,
                    default => $sign * strcmp($a['data_recibo'], $b['data_recibo']),
                };
            }

            return strcmp($b['created_at'], $a['created_at']) ?: $b['id'] <=> $a['id'];
        };
    }
}
