<?php

namespace App\Actions\AgentNotes;

use App\Enums\Notice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * cron/debitNotesAtrasadas (legado): notas de débito e crédito de exportação em aberto emitidas há 30 dias ou mais, agrupadas
 * por agente, ao financeiro, à Marcia e ao TI. Agentes com saldo negativo (a DMF deve a eles) e o agente 3198 ficam de fora, e
 * crédito entra negativo no total do agente.
 *
 * Diferença do legado: o TOTAL GERAL soma todos os agentes. O legado somava o total de cada agente só na volta seguinte do
 * laço, então o último agente da lista nunca entrava no geral.
 */
class OverdueNotesNotice
{
    private const IGNORED_AGENT = 3198;

    private const DAYS = 30;

    /** @return int notas listadas */
    public function __invoke(): int
    {
        if (! Notice::OverdueNotes->enabled()) {
            return 0;
        }

        $notes = $this->notes();

        $html = $notes->isEmpty() ? 'Nenhuma nota encontrada' : $this->table($notes);

        Mail::html($html, fn ($mail) => $mail
            ->to(Notice::OverdueNotes->recipients())
            ->replyTo('chamado@dmflog.com.br')
            ->subject('[FINANCEIRO] Relação de Notas de Debito/Crédito emitidas +30 dias'));

        return $notes->count();
    }

    /** @return Collection<int, array{agente: string, nota: ?string, emissao: string, valor: float, simbolo: ?string, network: ?string}> */
    private function notes(): Collection
    {
        $negative = collect(DB::select($this->balanceSql()))->pluck('ref_agente')->all();
        $limit = today()->subDays(self::DAYS)->toDateString();

        $base = fn (string $table) => DB::table("{$table} as n")
            ->join('embarque as e', 'e.embarque_id', '=', 'n.ref_embarque')
            ->join('pessoa as p', 'p.pessoa_id', '=', 'n.ref_agente')
            ->join('cmn_currencies as c', 'c.id', '=', 'n.moeda_id')
            ->leftJoin('network as nw', 'nw.network_id', '=', 'p.network_id')
            ->where('e.impor_expor', 'e')
            ->where('n.status_pagamento', 0)
            ->where('n.created_at', '<=', $limit)
            ->where('p.pessoa_id', '<>', self::IGNORED_AGENT)
            ->whereNotIn('p.pessoa_id', $negative)
            ->orderBy('p.nome');

        $columns = ['p.nome as agente', 'n.name as nota', 'n.created_at', 'c.symbol as simbolo', 'nw.nome as network'];

        $debits = $base('fin_debit_notes')
            ->select($columns)
            ->selectRaw('coalesce(n.due_to_dmf, 0) + coalesce(n.handling, 0) + coalesce(n.origin_comission, 0) + coalesce(n.due_to_shipper_agent, 0) + coalesce(n.exw_expenses, 0) as valor')
            ->get()
            ->map(fn ($n) => $this->row($n, (float) $n->valor));

        $credits = $base('fin_credit_notes')
            ->select($columns)
            ->selectRaw('coalesce(n.due_to_dmf_agent, 0) + coalesce(n.due_to_cnee_agent, 0) as valor')
            ->get()
            ->map(fn ($n) => $this->row($n, -(float) $n->valor));

        return $debits->concat($credits)->filter(fn ($n) => $n['valor'] != 0)->values();
    }

    /** @return array{agente: string, nota: ?string, emissao: string, valor: float, simbolo: ?string, network: ?string} */
    private function row(object $note, float $value): array
    {
        return [
            'agente' => (string) $note->agente,
            'nota' => $note->nota,
            'emissao' => date('d/m/Y', strtotime($note->created_at)),
            'valor' => $value,
            'simbolo' => $note->simbolo,
            'network' => $note->network,
        ];
    }

    /** @param  Collection<int, array{agente: string, nota: ?string, emissao: string, valor: float, simbolo: ?string, network: ?string}>  $notes */
    private function table(Collection $notes): string
    {
        $html = '<table border=1><tr><th>Agente</th><th>Nota</th><th>Data de Emissão</th><th>Valor</th><th>Network</th></tr>';

        foreach ($notes->groupBy('agente') as $agent => $agentNotes) {
            foreach ($agentNotes as $n) {
                $html .= '<tr><td>'.e($n['agente']).'</td><td>'.e($n['nota']).'</td>'
                    ."<td align='right'>{$n['emissao']}</td>"
                    ."<td align='right'>".e($n['simbolo']).' '.number_format($n['valor'], 2, ',', '.').'</td>'
                    .'<td>'.e($n['network']).'</td></tr>';
            }

            $html .= "<tr style='font-weight: bold'><td>TOTAL</td><td /><td />"
                ."<td align='right'>".number_format($agentNotes->sum('valor'), 2, ',', '.').'</td><td /></tr>';
        }

        return $html."<tr style='font-weight: bold'><td>TOTAL GERAL</td><td /><td />"
            ."<td align='right'>".number_format($notes->sum('valor'), 2, ',', '.').'</td><td /></tr></table>';
    }

    /**
     * Saldo por agente com o SQL do legado, inclusive o UNION (não UNION ALL) e a posição das colunas: as notas de débito de
     * importação e as despesas variadas entram na coluna "credito". Quem tem saldo (débito - crédito) negativo fica fora do aviso.
     */
    private function balanceSql(): string
    {
        $open = fn (string $alias) => "({$alias}.status_pagamento = 0 or {$alias}.status_pagamento is null)";
        $notCourier = "and trim(dv.shipper) <> 'COURRIER' and trim(dv.shipper) <> 'COURIER'";

        return "select ref_agente, sum(debito - credito) as saldo from (
            select cn.ref_agente, sum(coalesce(cn.due_to_dmf_agent, 0) + coalesce(cn.due_to_cnee_agent, 0)) as credito, 0 as debito
            from fin_credit_notes cn
            inner join cmn_currencies cc on cn.moeda_id = cc.id
            inner join embarque e on cn.ref_embarque = e.embarque_id
            inner join pessoa pe on pe.pessoa_id = cn.ref_agente
            where {$open('cn')} and e.impor_expor = 'e'
            group by 1
            union
            select dn.ref_agente, sum(coalesce(dn.due_to_dmf, 0) + coalesce(dn.handling, 0) + coalesce(dn.origin_comission, 0) + coalesce(dn.due_to_shipper_agent, 0) + coalesce(dn.exw_expenses, 0)), 0 as debito
            from fin_debit_notes dn
            inner join cmn_currencies cc on dn.moeda_id = cc.id
            inner join embarque e on dn.ref_embarque = e.embarque_id
            inner join pessoa pe on pe.pessoa_id = dn.ref_agente
            where {$open('dn')} and e.impor_expor = 'i'
            group by 1
            union
            select dv.agente_id, sum(dv.valor), 0 as debito
            from fin_despesas_variadas dv
            inner join cmn_currencies cc on dv.cmn_currencies_id = cc.id
            inner join pessoa pe on pe.pessoa_id = dv.agente_id
            where {$open('dv')} and dv.tipo_nota = 1 {$notCourier} and dv.impor_expor = 'e'
            group by 1
            union
            select dv.agente_id, sum(dv.valor), 0 as debito
            from fin_despesas_variadas dv
            inner join cmn_currencies cc on dv.cmn_currencies_id = cc.id
            inner join pessoa pe on pe.pessoa_id = dv.agente_id
            where {$open('dv')} and dv.tipo_nota = 2 {$notCourier} and dv.impor_expor = 'i'
            group by 1
            union
            select cn.ref_agente, 0 as credito, sum(coalesce(cn.due_to_dmf, 0)) as debito
            from fin_debit_notes cn
            inner join cmn_currencies cc on cn.moeda_id = cc.id
            inner join embarque e on cn.ref_embarque = e.embarque_id
            inner join pessoa pe on pe.pessoa_id = cn.ref_agente
            where {$open('cn')} and e.impor_expor = 'e'
            group by 1
        ) T group by 1 having saldo < 0";
    }
}
