<?php

namespace App\Actions\AgentNotes;

use Illuminate\Database\Query\Builder;
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
 * paid(): controleNotas/list ("Remover Baixa de Notas"). Notas baixadas no período (data de pagamento), com agente e as
 * duas datas obrigatórios; o crédito vale sempre due_to_dmf_agent + due_to_cnee_agent.
 */
class AgentNoteLists
{
    private const DEBIT_VALUE = 'coalesce(n.due_to_dmf, 0) + coalesce(n.handling, 0) + coalesce(n.origin_comission, 0) + coalesce(n.due_to_shipper_agent, 0) + coalesce(n.exw_expenses, 0)';

    private const EXPORT_CREDIT_VALUE = 'coalesce(n.due_to_dmf_agent, 0) + coalesce(n.due_to_cnee_agent, 0)';

    private const IMPORT_CREDIT_VALUE = 'coalesce(n.due_to_dmf, 0) + coalesce(n.due_to_cnee_agent, 0)';

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
        if (! filled($from) || ! filled($to)) {
            return collect();
        }

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

    /** Nota de crédito ou débito com a reserva e o cliente (shipper) da reserva. */
    private function shipmentNotes(string $table, string $type, string $valueExpression): Builder
    {
        return DB::table("{$table} as n")
            ->join('cmn_currencies as c', 'n.moeda_id', '=', 'c.id')
            ->join('embarque as e', 'n.ref_embarque', '=', 'e.embarque_id')
            ->join('pessoa as p', 'p.pessoa_id', '=', 'e.pessoa_id_cliente')
            ->selectRaw("n.id, n.name, e.booking, e.invoice, e.house_bl, p.nome as shipper, ({$valueExpression}) as valor, c.symbol, '{$type}' as tipo, n.created_at, n.data_pagamento");
    }

    /** Nota variada: sem reserva (só o texto do booking); o alias n deixa o filtro de baixa igual ao das outras notas. */
    private function variedNotes(int $agentId): Builder
    {
        return DB::table('fin_despesas_variadas as n')
            ->join('cmn_currencies as c', 'n.cmn_currencies_id', '=', 'c.id')
            ->where('n.agente_id', $agentId)
            ->selectRaw("n.fin_despesas_variadas_id as id, '' as name, n.booking, n.invoice, n.hbl as house_bl, n.shipper, n.valor, c.symbol, 'v' as tipo, n.data_despesa as created_at, n.data_pagamento");
    }

    /** Do primeiro ao último dia inteiros (o legado comparava a data sem hora e perdia as notas do último dia). */
    private function between(Builder $query, string $column, string $from, string $to): Builder
    {
        return $query
            ->where($column, '>=', Carbon::parse($from)->toDateString())
            ->where($column, '<', Carbon::parse($to)->addDay()->toDateString());
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
