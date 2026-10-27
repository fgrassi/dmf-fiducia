<?php

namespace App\Actions\Cobranca\Notices;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * cron/avisoVencimentoDespesa (legado): lembrete ao financeiro dos vencimentos fixos (despesa_vencimento) que caem daqui a
 * 3 dias, com os lançamentos pendentes (despesa status 2) de cada fornecedor/categoria. Dia além do fim do mês vence no
 * último dia (dia 31 em fevereiro vence no 28/29).
 */
class ExpenseDueNotice
{
    private const PENDING = 2;

    /** @return int vencimentos avisados */
    public function __invoke(): int
    {
        $target = today()->addDays(3);
        $day = $target->day;
        $lastDay = $target->daysInMonth;
        $due = $target->format('d/m/Y');

        $items = DB::table('despesa_vencimento as v')
            ->join('fornecedor as f', 'f.fornecedor_id', '=', 'v.fornecedor_id')
            ->join('categoria_despesa as c', 'c.categoria_despesa_id', '=', 'v.categoria_despesa_id')
            ->where(fn ($q) => $day === $lastDay ? $q->where('v.dia_vencimento', '>=', $day) : $q->where('v.dia_vencimento', $day))
            ->orderBy('f.nome')
            ->orderBy('c.nome')
            ->orderBy('v.descricao')
            ->get(['v.*', 'f.nome as fornecedor', 'c.nome as categoria']);

        if ($items->isEmpty()) {
            return 0;
        }

        $table = '<table border="1" cellpadding="4" cellspacing="0"><tr><th>Fornecedor</th><th>Categoria</th><th>Descrição</th><th>Dia de Vencimento</th><th>Data do Vencimento</th></tr>';
        $details = '';

        foreach ($items as $v) {
            $table .= '<tr><td>'.e($v->fornecedor).'</td><td>'.e($v->categoria).'</td><td>'.(filled($v->descricao) ? e($v->descricao) : '-').'</td>'
                .'<td align="center">'.$v->dia_vencimento.($v->dia_vencimento > $lastDay ? ' <small>(último dia do mês)</small>' : '').'</td>'
                ."<td align=\"center\">{$due}</td></tr>";

            $expenses = DB::table('despesa')
                ->where('fornecedor_id', $v->fornecedor_id)
                ->where('categoria_despesa_id', $v->categoria_despesa_id)
                ->where('status', self::PENDING)
                ->orderBy('data_prevista')
                ->get(['valor', 'data_prevista', 'descricao']);

            $details .= '<h4>'.e($v->fornecedor.' / '.$v->categoria.(filled($v->descricao) ? " — {$v->descricao}" : '')).'</h4>';
            $details .= $expenses->isEmpty()
                ? '<p><em>Nenhum lançamento aberto.</em></p>'
                : '<table border="1" cellpadding="4" cellspacing="0" style="margin-bottom: 16px;"><tr><th>Valor</th><th>Data Prevista</th><th>Descrição</th></tr>'
                    .$expenses->map(fn ($d) => '<tr><td align="right">'.number_format((float) $d->valor, 2, ',', '.').'</td>'
                        .'<td align="center">'.(filled($d->data_prevista) ? date('d/m/Y', strtotime($d->data_prevista)) : '-').'</td>'
                        .'<td>'.e($d->descricao).'</td></tr>')->implode('')
                    .'</table>';
        }

        $html = "<table width='1024px' border=0><tr bgcolor='#7e0001'><td></td></tr>"
            ."<tr><td><font face='Arial' color='black'><h3>Lembrete: os vencimentos abaixo ocorrem em 3 dias ({$due})</h3></font></td></tr>"
            ."<tr><td>{$table}</table></td></tr>"
            ."<tr><td><br/><h3>Despesas Cadastradas/Pendentes</h3><hr/>{$details}</td></tr></table>";

        Mail::html($html, fn ($mail) => $mail
            ->to(['financeiro@dmflog.com.br', 'it@dmflog.com.br'])
            ->subject("[Aviso] Vencimento de despesas em 3 dias ({$due})"));

        return $items->count();
    }
}
