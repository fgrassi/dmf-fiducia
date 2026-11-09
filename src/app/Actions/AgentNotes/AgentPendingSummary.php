<?php

namespace App\Actions\AgentNotes;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * resumoNotasAgentes/index (legado), "Relatório Pendências Agente": por agente, o que a DMF deve a ele ("due to agente") e o
 * que ele deve à DMF ("due to DMF"), somando as notas em aberto (crédito, débito e variadas) sem separar moeda, como o legado.
 *
 * $all = false: agentes sem restrição de país e só os que têm algum valor > 0 (o botão padrão do legado). $all = true: agentes
 * com restrição, todos, mesmo sem pendência. O AIC Ásia (3548) não é linha: o legado soma os valores dele na linha do AIC
 * (1838), mas a coluna Total da linha do 1838 continua sem eles.
 * O legado juntava as notas pela tabela de países do agente, o que multiplicava a soma pelo número de linhas dela; aqui só se
 * confere se o agente tem a restrição (no dump é uma linha por agente, o resultado é o mesmo).
 */
class AgentPendingSummary
{
    public const AIC = 1838;

    public const AIC_ASIA = 3548;

    /** Lados das notas: [lado, tabela, valor, tipo da reserva]; o crédito do lado DMF soma também due_to_dmf. */
    private const SHIPMENT_NOTES = [
        ['agent', 'fin_credit_notes', AgentNoteLists::EXPORT_CREDIT_VALUE, 'e'],
        ['agent', 'fin_debit_notes', AgentNoteLists::DEBIT_VALUE, 'i'],
        ['dmf', 'fin_credit_notes', 'coalesce(n.due_to_dmf, 0) + '.AgentNoteLists::EXPORT_CREDIT_VALUE, 'i'],
        ['dmf', 'fin_debit_notes', AgentNoteLists::DEBIT_VALUE, 'e'],
    ];

    /** [lado, tipo_nota, importação/exportação, regra do shipper]. */
    private const VARIED_NOTES = [
        ['agent', 1, 'e', null],
        ['agent', 2, 'i', "n.shipper <> 'COURRIER'"],
        ['dmf', 2, 'e', null],
        ['dmf', 1, 'i', null],
        ['dmf', 2, 'i', "n.shipper = 'COURRIER'"],
    ];

    /**
     * @return Collection<int, array{pessoa_id: int, agente: string, data_limite_cambio: ?string, contato: ?string, data_obs: ?string, due_to_agente: float, due_to_dmf: float, total: float}>
     */
    public function __invoke(bool $all): Collection
    {
        $restriction = $all ? 1 : 0;
        $sums = $this->sums();
        $asia = $this->rows($restriction, $all, [self::AIC_ASIA], $sums)->first();

        return $this->rows($restriction, $all, null, $sums)
            ->map(function (array $row) use ($asia) {
                if ($row['pessoa_id'] === self::AIC && $asia) {
                    $row['due_to_agente'] += $asia['due_to_agente'];
                    $row['due_to_dmf'] += $asia['due_to_dmf'];
                }

                return $row;
            })
            ->values();
    }

    /**
     * @param  list<int>|null  $only  null = todos menos o AIC Ásia
     * @param  array<int, array{agent: float, dmf: float}>  $sums
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(int $restriction, bool $all, ?array $only, array $sums): Collection
    {
        return DB::table('pessoa as a')
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('pessoa_tipo_pessoa as ptp')->whereColumn('ptp.pessoa_id', 'a.pessoa_id')->where('ptp.tipo_pessoa_id', 1))
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('agente_pais as ap')->whereColumn('ap.agente_id', 'a.pessoa_id')->where('ap.restricao', $restriction))
            ->when($only, fn ($query) => $query->whereIn('a.pessoa_id', $only), fn ($query) => $query->where('a.pessoa_id', '<>', self::AIC_ASIA))
            ->select('a.pessoa_id', 'a.nome as agente', 'a.data_limite_cambio', 'a.contato_agente_financeiro as contato')
            ->selectSub(
                DB::table('agente_observacao as ao')->whereColumn('ao.agente_id', 'a.pessoa_id')->orderByDesc('ao.data_observacao')->limit(1)->select('ao.data_observacao'),
                'data_obs',
            )
            ->orderByRaw('a.data_limite_cambio is null')
            ->orderBy('a.data_limite_cambio')
            ->orderBy('a.nome')
            ->get()
            ->map(fn (object $agent) => [
                'pessoa_id' => (int) $agent->pessoa_id,
                'agente' => (string) $agent->agente,
                'data_limite_cambio' => $agent->data_limite_cambio,
                'contato' => $agent->contato,
                'data_obs' => $agent->data_obs,
                'due_to_agente' => $due = (float) ($sums[$agent->pessoa_id]['agent'] ?? 0),
                'due_to_dmf' => $dmf = (float) ($sums[$agent->pessoa_id]['dmf'] ?? 0),
                'total' => $due - $dmf,
            ])
            ->when(! $all, fn (Collection $rows) => $rows->filter(fn (array $row) => $row['due_to_agente'] > 0 || $row['due_to_dmf'] > 0))
            ->values();
    }

    /** @return array<int, array{agent: float, dmf: float}> por agente, só notas em aberto */
    private function sums(): array
    {
        $open = fn ($query) => $query->where(fn ($query) => $query->where('n.status_pagamento', 0)->orWhereNull('n.status_pagamento'));
        $sums = [];

        foreach (self::SHIPMENT_NOTES as [$side, $table, $value, $mode]) {
            $query = DB::table("{$table} as n")
                ->join('embarque as e', 'e.embarque_id', '=', 'n.ref_embarque')
                ->where('e.impor_expor', $mode)
                ->tap($open)
                ->groupBy('n.ref_agente')
                ->selectRaw("n.ref_agente as agent_id, sum({$value}) as total");

            $this->add($sums, $side, $query->get());
        }

        foreach (self::VARIED_NOTES as [$side, $noteType, $mode, $shipperRule]) {
            $query = DB::table('fin_despesas_variadas as n')
                ->where('n.tipo_nota', $noteType)
                ->where('n.impor_expor', $mode)
                ->when($shipperRule, fn ($query) => $query->whereRaw($shipperRule))
                ->tap($open)
                ->groupBy('n.agente_id')
                ->selectRaw('n.agente_id as agent_id, sum(n.valor) as total');

            $this->add($sums, $side, $query->get());
        }

        return $sums;
    }

    /**
     * @param  array<int, array{agent: float, dmf: float}>  $sums
     * @param  Collection<int, object>  $rows
     */
    private function add(array &$sums, string $side, Collection $rows): void
    {
        foreach ($rows as $row) {
            $sums[$row->agent_id] ??= ['agent' => 0.0, 'dmf' => 0.0];
            $sums[$row->agent_id][$side] += (float) $row->total;
        }
    }
}
