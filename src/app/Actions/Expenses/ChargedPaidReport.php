<?php

namespace App\Actions\Expenses;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * relatorioDespesasCobradasPagas (legado): por despesa e por reserva, o que foi cobrado (itens de cobrança, IC) contra o
 * que foi pago (ordens de pagamento e "Outros"), mais as capas de contabilidade manuais. Cada reserva aparece uma vez
 * por despesa; o resultado é cobrado − pago. O filtro de data escolhe o eixo (cobrança, pagamento, ETD ou ETA); quando o
 * eixo é um dos dois lados, o outro lado entra só para as mesmas linhas de despesa (`fin_expenses_values`) do período.
 *
 * Uma capa manual só entra no eixo de ETD/ETA se o filtro for por arquivo de capa (ela não tem embarque).
 *
 * @phpstan-type Filters array{data_ini:?string, data_fim:?string, tipo_data:?string, despesa_ids:?array<int,int|string>, regra_ids:?array<int,int|string>, cliente_id:int|string|null, arquivo_id:int|string|null}
 */
class ChargedPaidReport
{
    public const DATE_AXES = ['cobranca' => 'Data da cobrança', 'pagamento' => 'Data do pagamento', 'etd' => 'ETD', 'eta' => 'ETA'];

    private const ETD = 'COALESCE(e.saida_efetiva, e.saida_prevista)';

    private const ETA = 'COALESCE(e.chegada_efetiva, e.chegada_prevista)';

    /**
     * @param  Filters  $input
     * @return array{grupos: list<array<string, mixed>>, totais: array{reservas:int, cobrado:float, pago:float, resultado:float}, periodo: string}
     *
     * @throws DomainException filtro inválido (mesmas mensagens do legado)
     */
    public function __invoke(array $input): array
    {
        $filters = $this->parse($input);

        $rows = [];

        foreach ($this->charged($filters) as $row) {
            $line = &$this->line($rows, $row);
            $line['cobrado'] += (float) $row->cobrado;
            $line['data_cobranca'] = $this->earliest($line['data_cobranca'], $row->data_cobranca);
        }

        foreach ($this->paid($filters) as $row) {
            $line = &$this->line($rows, $row);
            $line['pago'] += (float) $row->pago;
            $line['data_pagamento'] = $this->earliest($line['data_pagamento'], $row->data_pagamento);
        }

        foreach ($this->manualCovers($filters) as $row) {
            $row->embarque_id = 0;
            $row->booking = 'Capa manual '.($row->numero ?? $row->capa_manual_id);
            $line = &$this->line($rows, $row);
            $line['cobrado'] += (float) $row->cobrado;
            $line['pago'] += (float) $row->pago;
            $line['data_cobranca'] = $this->earliest($line['data_cobranca'], $row->data_cobranca);
            $line['data_pagamento'] = $this->earliest($line['data_pagamento'], $row->data_pagamento);
            $line['cliente_nome'] = $line['cliente_nome'] ?: (string) $row->cliente_nome_capa;
        }
        unset($line);

        $this->fillClients($rows);

        $period = $this->periodLabel($filters);
        $groups = [];
        $totals = ['reservas' => 0, 'cobrado' => 0.0, 'pago' => 0.0, 'resultado' => 0.0];

        foreach ($rows as $row) {
            $row['resultado'] = $row['cobrado'] - $row['pago'];
            $group = &$groups[$row['despesa_id']];
            $group ??= [
                'despesa_id' => $row['despesa_id'],
                'despesa_nome' => $row['despesa_nome'],
                'despesa_tipo' => $row['despesa_tipo'],
                'titulo' => $row['despesa_nome'].($row['despesa_tipo'] !== '' ? " / {$row['despesa_tipo']}" : '').' / '.$period,
                'reservas' => 0, 'cobrado' => 0.0, 'pago' => 0.0, 'resultado' => 0.0, 'rows' => [],
            ];
            $group['rows'][] = $row;
            $group['reservas']++;
            $totals['reservas']++;

            foreach (['cobrado', 'pago', 'resultado'] as $key) {
                $group[$key] += $row[$key];
                $totals[$key] += $row[$key];
            }
            unset($group);
        }

        uasort($groups, fn (array $a, array $b) => strcasecmp($a['despesa_nome'], $b['despesa_nome']));

        return [
            'grupos' => array_values(array_map(function (array $group) {
                usort($group['rows'], fn (array $a, array $b) => strcasecmp($a['booking'], $b['booking']));

                return $group;
            }, $groups)),
            'totais' => $totals,
            'periodo' => $period,
        ];
    }

    /**
     * @param  Filters  $input
     * @return array<string, mixed>
     *
     * @throws DomainException
     */
    private function parse(array $input): array
    {
        $start = $this->date($input['data_ini'] ?? null);
        $end = $this->date($input['data_fim'] ?? null);
        $fileId = (int) ($input['arquivo_id'] ?? 0);

        if (($start && ! $end) || (! $start && $end)) {
            throw new DomainException('Informe a data inicial e a data final.');
        }

        if (! $start && ! $fileId) {
            throw new DomainException('Informe o período ou o arquivo de capa.');
        }

        if ($start && $start > $end) {
            throw new DomainException('A data inicial deve ser anterior ou igual à data final.');
        }

        $axis = $input['tipo_data'] ?? 'cobranca';
        $file = $fileId ? DB::table('capa_processo_arquivo')->where('capa_processo_arquivo_id', $fileId)->first() : null;

        if ($fileId && ! $file) {
            throw new DomainException('Arquivo de capa não encontrado.');
        }

        $ids = fn (?array $values) => array_values(array_unique(array_filter(array_map('intval', $values ?? []))));

        return [
            'from' => $start?->toDateString(),
            'to_exclusive' => $end?->addDay()->toDateString(),
            'from_label' => $start?->format('d/m/Y'),
            'to_label' => $end?->format('d/m/Y'),
            'axis' => array_key_exists($axis, self::DATE_AXES) ? $axis : 'cobranca',
            'expenses' => $ids($input['despesa_ids'] ?? null),
            'rules' => $ids($input['regra_ids'] ?? null),
            'client' => (int) ($input['cliente_id'] ?? 0),
            'file' => $fileId,
            'file_name' => (string) $file?->nome,
        ];
    }

    private function date(?string $value): ?CarbonImmutable
    {
        return filled($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))
            ? CarbonImmutable::parse($value)
            : null;
    }

    /** @param  array<string, mixed>  $filters */
    private function charged(array $filters): iterable
    {
        $query = $this->expenseBase('fin_charges_items as fci', 'fci.ref_expense')
            ->join('fin_charges as fc', 'fc.id', '=', 'fci.ref_charge')
            ->selectRaw('MIN(fc.created_at) as data_cobranca, SUM(fci.item_value) as cobrado')
            ->groupBy('fe.id', 'fe.name', 'feu.acronym', 'e.embarque_id', 'e.booking', 'e.cliente_id')
            ->groupByRaw(self::ETD)
            ->groupByRaw(self::ETA);

        $this->commonFilters($query, $filters);

        if ($filters['from']) {
            match ($filters['axis']) {
                'cobranca' => $this->between($query, 'fc.created_at', $filters),
                'etd' => $this->between($query, DB::raw(self::ETD), $filters),
                'eta' => $this->between($query, DB::raw(self::ETA), $filters),
                default => $query->whereIn('fev.id', $this->paidValueIds($filters)),
            };
        }

        return $query->get();
    }

    /** @param  array<string, mixed>  $filters */
    private function paid(array $filters): iterable
    {
        $orders = $this->expenseBase('fin_payment_order_items as fpoi', 'fpoi.ref_expense')
            ->join('fin_payment_orders as fpo', 'fpo.id', '=', 'fpoi.ref_payment_order')
            ->where(fn ($q) => $q->whereNull('fpo.status')->orWhere('fpo.status', '<>', 2))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('fin_payment_order_items_disabled as fpoid')
                ->whereColumn('fpoid.ref_fin_payment_order', 'fpoi.ref_payment_order')
                ->whereColumn('fpoid.ref_expense', 'fpoi.ref_expense')
                ->where('fpoid.status', 0))
            ->selectRaw('fpo.payment_date as data_pagamento, fpoi.item_value as pago');

        $others = $this->expenseBase('ordem_pagamento_item as opi', 'opi.ref_expense')
            ->join('ordem_pagamento as op', 'op.ordem_pagamento_id', '=', 'opi.ordem_pagamento_id')
            ->where(fn ($q) => $q->whereNull('op.status')->orWhere('op.status', '<>', 2))
            ->selectRaw('op.payment_date as data_pagamento, opi.item_value as pago');

        foreach ([[$orders, 'fpo.payment_date'], [$others, 'op.payment_date']] as [$query, $paymentDate]) {
            $this->commonFilters($query, $filters);

            if ($filters['from']) {
                match ($filters['axis']) {
                    'pagamento' => $this->between($query, $paymentDate, $filters),
                    'etd' => $this->between($query, DB::raw(self::ETD), $filters),
                    'eta' => $this->between($query, DB::raw(self::ETA), $filters),
                    default => $query->whereIn('fev.id', $this->chargedValueIds($filters)),
                };
            }
        }

        return DB::query()->fromSub($orders->unionAll($others), 't')
            ->selectRaw('t.despesa_id, t.despesa_nome, t.despesa_tipo, t.embarque_id, t.booking, t.cliente_id, t.etd, t.eta, MIN(t.data_pagamento) as data_pagamento, SUM(t.pago) as pago')
            ->groupBy('t.despesa_id', 't.despesa_nome', 't.despesa_tipo', 't.embarque_id', 't.booking', 't.cliente_id', 't.etd', 't.eta')
            ->get();
    }

    /** Capas manuais (ex.: courier): valores lançados direto, em reais ou convertidos pela cotação da capa. */
    private function manualCovers(array $filters): iterable
    {
        $query = DB::table('capa_processo_manual as cpm')
            ->join('fin_expenses as fe', 'fe.id', '=', 'cpm.ref_expense')
            ->leftJoin('fin_expenses_unities as feu', 'feu.id', '=', 'fe.ref_unity')
            ->whereNotNull('cpm.ref_expense')
            ->where(fn ($q) => $q->whereRaw('COALESCE(cpm.buying_rate, 0) <> 0')->orWhereRaw('COALESCE(cpm.selling_rate, 0) <> 0'))
            ->selectRaw("fe.id as despesa_id, fe.name as despesa_nome, COALESCE(feu.acronym, '') as despesa_tipo, cpm.cliente_id,
                COALESCE(cpm.nome_cliente, '') as cliente_nome_capa, cpm.capa_processo_manual_id as capa_manual_id, cpm.numero,
                MIN(DATE(cpm.created_at)) as data_cobranca, MIN(cpm.payment_date) as data_pagamento,
                SUM(CASE WHEN COALESCE(cpm.ref_currency_sr, 1) = 1 THEN COALESCE(cpm.selling_rate, 0) ELSE COALESCE(cpm.selling_rate, 0) * COALESCE(cpm.valor_cotacao, 0) END) as cobrado,
                SUM(CASE WHEN COALESCE(cpm.ref_currency_br, 1) = 1 THEN COALESCE(cpm.buying_rate, 0) ELSE COALESCE(cpm.buying_rate, 0) * COALESCE(cpm.valor_cotacao, 0) END) as pago")
            ->groupBy('fe.id', 'fe.name', 'feu.acronym', 'cpm.cliente_id', 'cpm.nome_cliente', 'cpm.capa_processo_manual_id', 'cpm.numero');

        if ($filters['client']) {
            $query->where('cpm.cliente_id', $filters['client']);
        }
        $this->expenseFilters($query, $filters);
        if ($filters['file']) {
            $query->where('cpm.capa_processo_arquivo_id', $filters['file']);
        }

        if ($filters['from']) {
            if (in_array($filters['axis'], ['etd', 'eta'], true)) {
                if (! $filters['file']) {
                    return [];
                }
            } else {
                $this->between($query, DB::raw($filters['axis'] === 'pagamento' ? 'COALESCE(cpm.payment_date, DATE(cpm.created_at))' : 'DATE(cpm.created_at)'), $filters);
            }
        }

        return $query->get();
    }

    /** Base de despesa por reserva: item (cobrança ou ordem) → valor de despesa → despesa, unidade e reserva. */
    private function expenseBase(string $items, string $valueColumn): Builder
    {
        return DB::table($items)
            ->join('fin_expenses_values as fev', 'fev.id', '=', $valueColumn)
            ->join('fin_expenses as fe', 'fe.id', '=', 'fev.ref_expense')
            ->leftJoin('fin_expenses_unities as feu', 'feu.id', '=', 'fe.ref_unity')
            ->join('embarque as e', 'e.embarque_id', '=', 'fev.ref_embarque')
            ->selectRaw("fe.id as despesa_id, fe.name as despesa_nome, COALESCE(feu.acronym, '') as despesa_tipo, e.embarque_id, e.booking, e.cliente_id, ".self::ETD.' as etd, '.self::ETA.' as eta');
    }

    /** @param  array<string, mixed>  $filters */
    private function commonFilters(Builder $query, array $filters): void
    {
        if ($filters['client']) {
            $query->where('e.cliente_id', $filters['client']);
        }
        $this->expenseFilters($query, $filters);
        if ($filters['file']) {
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('capa_processo as cp')
                ->whereColumn('cp.embarque_id', 'e.embarque_id')
                ->where('cp.capa_processo_arquivo_id', $filters['file']));
        }
    }

    /** @param  array<string, mixed>  $filters */
    private function expenseFilters(Builder $query, array $filters): void
    {
        if ($filters['expenses']) {
            $query->whereIn('fe.id', $filters['expenses']);
        }
        if ($filters['rules']) {
            $query->whereIn('fe.id', DB::table('regra_despesa_cobranca_item')->whereIn('regra_despesa_cobranca_id', $filters['rules'])->select('despesa_id'));
        }
    }

    /** Linhas de despesa cobradas no período (para o eixo "cobrança" visto do lado pago). @param  array<string, mixed>  $filters */
    private function chargedValueIds(array $filters): Builder
    {
        $query = DB::table('fin_charges_items as fci')
            ->join('fin_charges as fc', 'fc.id', '=', 'fci.ref_charge')
            ->join('fin_expenses_values as fev', 'fev.id', '=', 'fci.ref_expense')
            ->join('fin_expenses as fe', 'fe.id', '=', 'fev.ref_expense')
            ->join('embarque as e', 'e.embarque_id', '=', 'fev.ref_embarque')
            ->select('fci.ref_expense');
        $this->commonFilters($query, $filters);

        return $this->between($query, 'fc.created_at', $filters);
    }

    /** Linhas de despesa pagas no período (para o eixo "pagamento" visto do lado cobrado). @param  array<string, mixed>  $filters */
    private function paidValueIds(array $filters): Builder
    {
        $orders = DB::table('fin_payment_order_items as fpoi')
            ->join('fin_payment_orders as fpo', 'fpo.id', '=', 'fpoi.ref_payment_order')
            ->join('fin_expenses_values as fev', 'fev.id', '=', 'fpoi.ref_expense')
            ->join('fin_expenses as fe', 'fe.id', '=', 'fev.ref_expense')
            ->join('embarque as e', 'e.embarque_id', '=', 'fev.ref_embarque')
            ->where(fn ($q) => $q->whereNull('fpo.status')->orWhere('fpo.status', '<>', 2))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('fin_payment_order_items_disabled as fpoid')
                ->whereColumn('fpoid.ref_fin_payment_order', 'fpoi.ref_payment_order')
                ->whereColumn('fpoid.ref_expense', 'fpoi.ref_expense')
                ->where('fpoid.status', 0))
            ->select('fpoi.ref_expense');
        $others = DB::table('ordem_pagamento_item as opi')
            ->join('ordem_pagamento as op', 'op.ordem_pagamento_id', '=', 'opi.ordem_pagamento_id')
            ->join('fin_expenses_values as fev', 'fev.id', '=', 'opi.ref_expense')
            ->join('fin_expenses as fe', 'fe.id', '=', 'fev.ref_expense')
            ->join('embarque as e', 'e.embarque_id', '=', 'fev.ref_embarque')
            ->where(fn ($q) => $q->whereNull('op.status')->orWhere('op.status', '<>', 2))
            ->select('opi.ref_expense');

        foreach ([[$orders, 'fpo.payment_date'], [$others, 'op.payment_date']] as [$query, $paymentDate]) {
            $this->commonFilters($query, $filters);
            $this->between($query, $paymentDate, $filters);
        }

        // `in ((select …) union (select …))` não existe no MySQL: a união vai numa tabela derivada
        return DB::query()->fromSub($orders->union($others), 'u')->select('u.ref_expense');
    }

    /** @param  array<string, mixed>  $filters */
    private function between(Builder $query, mixed $column, array $filters): Builder
    {
        return $query->where($column, '>=', $filters['from'])->where($column, '<', $filters['to_exclusive']);
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function &line(array &$rows, object $row): array
    {
        $key = isset($row->capa_manual_id) ? "m{$row->capa_manual_id}_{$row->despesa_id}" : "{$row->despesa_id}_{$row->embarque_id}";

        $rows[$key] ??= [
            'despesa_id' => (int) $row->despesa_id,
            'despesa_nome' => (string) $row->despesa_nome,
            'despesa_tipo' => (string) $row->despesa_tipo,
            'embarque_id' => (int) ($row->embarque_id ?? 0),
            'booking' => (string) $row->booking,
            'cliente_id' => (int) ($row->cliente_id ?? 0),
            'cliente_nome' => (string) ($row->cliente_nome_capa ?? ''),
            'etd' => (string) ($row->etd ?? ''),
            'eta' => (string) ($row->eta ?? ''),
            'data_cobranca' => '',
            'data_pagamento' => '',
            'cobrado' => 0.0,
            'pago' => 0.0,
            'resultado' => 0.0,
        ];

        return $rows[$key];
    }

    private function earliest(string $current, ?string $candidate): string
    {
        return filled($candidate) && ($current === '' || $candidate < $current) ? (string) $candidate : $current;
    }

    /** @param  array<string, array<string, mixed>>  $rows */
    private function fillClients(array &$rows): void
    {
        $ids = collect($rows)->pluck('cliente_id')->filter()->unique()->all();

        if ($ids === []) {
            return;
        }

        $names = DB::table('pessoa')->whereIn('pessoa_id', $ids)->get(['pessoa_id', 'nome', 'cnpj'])
            ->mapWithKeys(fn ($person) => [$person->pessoa_id => $person->nome.(filled($person->cnpj) ? " ({$person->cnpj})" : '')]);

        foreach ($rows as &$row) {
            $row['cliente_nome'] = $names[$row['cliente_id']] ?? $row['cliente_nome'];
        }
    }

    /** @param  array<string, mixed>  $filters */
    private function periodLabel(array $filters): string
    {
        return collect([$filters['file_name'] ?: null, $filters['from'] ? "{$filters['from_label']} até {$filters['to_label']}" : null])->filter()->implode(' / ');
    }
}
