<?php

namespace App\Actions\AgentNotes;

use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Listas das telas de baixa das notas dos agentes. Cada linha tem a chave "tipo_id" (c = crédito, d = débito, v = nota
 * variada) que as telas devolvem a AgentNotePayments.
 *
 * open(): controleCreditDebitAgente/list (legado, "Baixa Notas de Crédito/Débito Agentes"). Sem período soma crédito,
 * débito e notas variadas em aberto. Com os dois limites, o filtro (data de criação) vale só para os créditos de
 * importação e os débitos: o crédito de exportação entra sem filtro de data e as notas variadas não aparecem. O valor do
 * crédito é due_to_dmf + due_to_cnee_agent na importação e due_to_dmf_agent + due_to_cnee_agent na exportação; crédito de
 * reserva cancelada (ou sem operação) não entra.
 *
 * paid(): controleNotas/list ("Remover Baixa de Notas"). Notas baixadas do agente no período (data de pagamento); um dos
 * limites pode faltar (no legado eram os dois obrigatórios); o crédito vale sempre due_to_dmf_agent + due_to_cnee_agent.
 *
 * statement(): controleCreditDebitAgente/consulta e getPdf ("Consultas Notas Agentes", D13): notas baixadas do agente nas
 * regras do PDF do extrato, separadas em "devido à DMF" e "devido ao agente". A tela e o PDF usam o mesmo conjunto (a tela
 * do legado somava outro).
 *
 * search(): consultaNotas ("Consulta Notas Debit/Credit"): notas de todos os agentes, abertas ou baixadas, com filtros.
 */
class AgentNoteLists
{
    public const DEBIT_VALUE = 'coalesce(n.due_to_dmf, 0) + coalesce(n.handling, 0) + coalesce(n.origin_comission, 0) + coalesce(n.due_to_shipper_agent, 0) + coalesce(n.exw_expenses, 0)';

    public const EXPORT_CREDIT_VALUE = 'coalesce(n.due_to_dmf_agent, 0) + coalesce(n.due_to_cnee_agent, 0)';

    public const IMPORT_CREDIT_VALUE = 'coalesce(n.due_to_dmf, 0) + coalesce(n.due_to_cnee_agent, 0)';

    /** @return Collection<int, array<string, mixed>> */
    public function open(int $agentId, ?string $from, ?string $to): Collection
    {
        $period = filled($from) && filled($to);
        $isOpen = fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('n.status_pagamento', 0)->orWhereNull('n.status_pagamento'));
        $created = fn (Builder $query) => $period ? $this->between($query, 'n.created_at', $from, $to) : $query;

        $exportCredits = $this->shipmentNotes('fin_credit_notes', 'c', self::EXPORT_CREDIT_VALUE)
            ->where('n.ref_agente', $agentId)
            ->where('e.impor_expor', 'e')
            ->where('e.finalizado', '<>', 'c')
            ->tap($isOpen);
        $importCredits = $created($this->shipmentNotes('fin_credit_notes', 'c', self::IMPORT_CREDIT_VALUE)
            ->where('n.ref_agente', $agentId)
            ->where('e.impor_expor', 'i')
            ->where('e.finalizado', '<>', 'c')
            ->tap($isOpen));
        $debits = $created($this->shipmentNotes('fin_debit_notes', 'd', self::DEBIT_VALUE)
            ->where('n.ref_agente', $agentId)
            ->tap($isOpen));

        $rows = $exportCredits->get()->concat($importCredits->get())->concat($debits->get());

        if (! $period) {
            $rows = $rows->concat($this->variedNotes($agentId)
                ->tap($isOpen)
                ->get());
        }

        return $this->keyed($rows);
    }

    /** @return Collection<int, array<string, mixed>> */
    public function paid(int $agentId, ?string $from, ?string $to): Collection
    {
        $paidInPeriod = fn (Builder $query) => $this->between($query->where('n.status_pagamento', 1), 'n.data_pagamento', $from, $to);

        $credits = $this->shipmentNotes('fin_credit_notes', 'c', self::EXPORT_CREDIT_VALUE)
            ->where('n.ref_agente', $agentId)
            ->tap($paidInPeriod);
        $debits = $this->shipmentNotes('fin_debit_notes', 'd', self::DEBIT_VALUE)
            ->where('n.ref_agente', $agentId)
            ->tap($paidInPeriod);
        $varied = $this->variedNotes($agentId)
            ->tap($paidInPeriod);

        return $this->keyed($credits->get()->concat($debits->get())->concat($varied->get()));
    }

    /** Valor do crédito na consulta de notas (getValor do legado): importação só due_to_dmf. */
    private const SEARCH_CREDIT_VALUE = "case when e.impor_expor = 'i' then coalesce(n.due_to_dmf, 0) else coalesce(n.due_to_dmf_agent, 0) + coalesce(n.due_to_cnee_agent, 0) end";

    private const DEBIT_TO_AGENT_VALUE = 'coalesce(n.due_to_dmf_agent, 0)';

    private const NOT_COURIER = "trim(n.shipper) not in ('COURIER', 'COURRIER')";

    private const COURIER = "trim(n.shipper) in ('COURIER', 'COURRIER')";

    /**
     * Extrato do agente: notas baixadas (período da data de pagamento, aberto de um lado se vier só uma data).
     * Devido ao agente: crédito de exportação, débito de importação e variadas (crédito de exportação, débito de
     * importação). Devido à DMF: crédito de importação, débito de exportação, variadas (débito de exportação, crédito
     * de importação) e as variadas de courier de importação. Variada de courier de exportação e sem shipper ficam fora.
     * Cada linha traz "label" (CREDIT, DEBIT ou COU) e "tipo_embarque" (e/i).
     *
     * @return array{dmf: Collection<string, array<string, mixed>>, agent: Collection<string, array<string, mixed>>}
     */
    public function statement(int $agentId, ?string $from, ?string $to): array
    {
        $paid = fn (Builder $query) => $this->between($query->where('n.status_pagamento', 1), 'n.data_pagamento', $from, $to);
        $shipment = fn (string $table, string $type, string $value, string $mode) => $this->shipmentNotes($table, $type, $value)
            ->where('n.ref_agente', $agentId)
            ->where('e.impor_expor', $mode)
            ->tap($paid);
        $varied = fn (string $noteType, string $mode, string $shipperRule = self::NOT_COURIER) => $this->variedNotes($agentId)
            ->whereRaw($shipperRule)
            ->where('n.tipo_nota', $noteType)
            ->where('n.impor_expor', $mode)
            ->tap($paid);

        return [
            'dmf' => $this->keyed(collect()
                ->concat($this->labeled($shipment('fin_credit_notes', 'c', self::IMPORT_CREDIT_VALUE, 'i'), 'CREDIT'))
                ->concat($this->labeled($shipment('fin_debit_notes', 'd', self::DEBIT_VALUE, 'e'), 'DEBIT'))
                ->concat($this->labeled($varied('2', 'e'), 'DEBIT'))
                ->concat($this->labeled($varied('1', 'i'), 'CREDIT'))
                ->concat($this->labeled($this->variedNotes($agentId)->whereRaw(self::COURIER)->where('n.impor_expor', 'i')->tap($paid), 'COU'))),
            'agent' => $this->keyed(collect()
                ->concat($this->labeled($shipment('fin_credit_notes', 'c', self::EXPORT_CREDIT_VALUE, 'e'), 'CREDIT'))
                ->concat($this->labeled($shipment('fin_debit_notes', 'd', self::DEBIT_TO_AGENT_VALUE, 'i'), 'DEBIT'))
                ->concat($this->labeled($varied('1', 'e'), 'CREDIT'))
                ->concat($this->labeled($varied('2', 'i'), 'DEBIT'))),
        ];
    }

    /**
     * @param  array{agente_id?: mixed, tipo?: ?string, status?: ?string, data_ini?: ?string, data_fim?: ?string, pagamento_ini?: ?string, pagamento_fim?: ?string, booking?: ?string, hbl?: ?string, invoice?: ?string, shipper?: ?string}  $filters
     * @return LengthAwarePaginator<int, object>
     */
    public function search(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $type = $filters['tipo'] ?? null;
        $like = fn (Builder $query, string $column, ?string $text) => filled($text) ? $query->where($column, 'like', "%{$text}%") : $query;
        $common = function (Builder $query, string $createdColumn, array $columns) use ($filters, $like) {
            $this->between($query, $createdColumn, $filters['data_ini'] ?? null, $filters['data_fim'] ?? null);
            $this->between($query, 'n.data_pagamento', $filters['pagamento_ini'] ?? null, $filters['pagamento_fim'] ?? null);

            if (filled($filters['agente_id'] ?? null)) {
                $query->where($columns['agent'], $filters['agente_id']);
            }

            if (($filters['status'] ?? null) === '1') {
                $query->where('n.status_pagamento', 1);
            } elseif (($filters['status'] ?? null) === '0') {
                $query->where(fn (Builder $query) => $query->where('n.status_pagamento', 0)->orWhereNull('n.status_pagamento'));
            }

            $like($query, $columns['booking'], $filters['booking'] ?? null);
            $like($query, $columns['hbl'], $filters['hbl'] ?? null);
            $like($query, $columns['invoice'], $filters['invoice'] ?? null);
            $like($query, $columns['shipper'], $filters['shipper'] ?? null);

            return $query;
        };
        $shipment = fn (string $table, string $label, string $value) => $common(
            $this->shipmentNotes($table, $label, $value)
                ->join('pessoa as ag', 'ag.pessoa_id', '=', 'n.ref_agente')
                ->addSelect('ag.nome as agente'),
            'n.created_at',
            ['agent' => 'n.ref_agente', 'booking' => 'e.booking', 'hbl' => 'e.house_bl', 'invoice' => 'e.invoice', 'shipper' => 'p.nome'],
        );

        $queries = [];

        if ($type !== 'd') {
            $queries[] = $shipment('fin_credit_notes', 'c', self::SEARCH_CREDIT_VALUE);
        }

        if ($type !== 'c') {
            $queries[] = $shipment('fin_debit_notes', 'd', self::DEBIT_VALUE);
        }

        $variedQuery = DB::table('fin_despesas_variadas as n')
            ->join('cmn_currencies as c', 'n.cmn_currencies_id', '=', 'c.id')
            ->join('pessoa as ag', 'ag.pessoa_id', '=', 'n.agente_id')
            ->selectRaw("n.fin_despesas_variadas_id as id, n.invoice as name, n.booking, n.invoice, n.hbl as house_bl, n.shipper, n.valor, c.symbol, 'v' as tipo, n.data_despesa as created_at, n.data_pagamento, n.cmn_currencies_id as moeda, n.impor_expor as tipo_embarque, n.status_pagamento, n.tipo_nota, ag.nome as agente");
        $queries[] = $common($variedQuery, 'n.data_despesa', ['agent' => 'n.agente_id', 'booking' => 'n.booking', 'hbl' => 'n.hbl', 'invoice' => 'n.invoice', 'shipper' => 'n.shipper'])
            ->when(filled($type), fn (Builder $query) => $query->where('n.tipo_nota', $type === 'c' ? 1 : 2));

        $union = array_shift($queries);

        foreach ($queries as $query) {
            $union->unionAll($query);
        }

        return DB::query()
            ->fromSub($union, 'x')
            ->orderByDesc('created_at')
            ->orderBy('tipo')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /** Linhas do extrato com o rótulo do tipo; a nota variada não tem nome próprio, o legado mostra a invoice. */
    private function labeled(Builder $query, string $label): Collection
    {
        return $query->get()->each(function (object $row) use ($label) {
            $row->label = $label;
            $row->name = $row->name ?: $row->invoice;
        });
    }

    /** Nota de crédito ou débito com a reserva e o cliente (shipper) da reserva. */
    private function shipmentNotes(string $table, string $type, string $valueExpression): Builder
    {
        return DB::table("{$table} as n")
            ->join('cmn_currencies as c', 'n.moeda_id', '=', 'c.id')
            ->join('embarque as e', 'n.ref_embarque', '=', 'e.embarque_id')
            ->join('pessoa as p', 'p.pessoa_id', '=', 'e.pessoa_id_cliente')
            ->selectRaw("n.id, n.name, e.booking, e.invoice, e.house_bl, p.nome as shipper, ({$valueExpression}) as valor, c.symbol, '{$type}' as tipo, n.created_at, n.data_pagamento, n.moeda_id as moeda, e.impor_expor as tipo_embarque, n.status_pagamento, null as tipo_nota");
    }

    /** Nota variada: sem reserva (só o texto do booking); o alias n deixa o filtro de baixa igual ao das outras notas. */
    private function variedNotes(int $agentId): Builder
    {
        return DB::table('fin_despesas_variadas as n')
            ->join('cmn_currencies as c', 'n.cmn_currencies_id', '=', 'c.id')
            ->where('n.agente_id', $agentId)
            ->selectRaw("n.fin_despesas_variadas_id as id, '' as name, n.booking, n.invoice, n.hbl as house_bl, n.shipper, n.valor, c.symbol, 'v' as tipo, n.data_despesa as created_at, n.data_pagamento, n.cmn_currencies_id as moeda, n.impor_expor as tipo_embarque, n.status_pagamento, n.tipo_nota");
    }

    /**
     * Do primeiro ao último dia inteiros (o legado comparava a data sem hora e perdia as notas do último dia). Um dos
     * limites pode faltar: o período fica aberto desse lado.
     */
    private function between(Builder $query, string $column, ?string $from, ?string $to): Builder
    {
        if (filled($from)) {
            $query->where($column, '>=', Carbon::parse($from)->toDateString());
        }

        if (filled($to)) {
            $query->where($column, '<', Carbon::parse($to)->addDay()->toDateString());
        }

        return $query;
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return Collection<string, array<string, mixed>>
     */
    private function keyed(Collection $rows): Collection
    {
        return $rows
            ->map(fn (object $row) => (array) $row + ['key' => "{$row->tipo}_{$row->id}"])
            ->sortBy([['created_at', 'asc'], ['tipo', 'asc'], ['id', 'asc']])
            ->keyBy('key');
    }
}
