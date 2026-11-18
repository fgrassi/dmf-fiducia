<?php

namespace App\Actions\Comissoes;

use App\Actions\Notices\RenderNoticeEmail;
use App\Actions\Notices\SendNoticeMail;
use App\Enums\Notice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * cron/avisoComissoesAbertas (legado): comissões de embarque em aberto (status vazio ou 0) criadas a partir de 01/01/2026,
 * por e-mail ao financeiro e à Marcia. Mesmo filtro do comissoes/loadComissionados.
 */
class OpenCommissionsNotice
{
    // ponytail: a data fixa é a do legado; vira config/parâmetro se o corte tiver de mudar a cada ano.
    private const SINCE = '2026-01-01';

    /** @return int comissões avisadas */
    public function __invoke(): int
    {
        if (! Notice::OpenCommissions->enabled()) {
            return 0;
        }

        $commissions = DB::table('comissao_embarque as q')
            ->join('comissao_embarque_responsavel as r', 'r.comissao_embarque_responsavel_id', '=', 'q.comissao_embarque_responsavel_id')
            ->join('embarque as e', 'e.embarque_id', '=', 'q.embarque_id')
            ->join('cmn_currencies as m', 'm.id', '=', 'q.moeda_id')
            ->where(fn ($query) => $query->whereNull('q.status')->orWhere('q.status', 0))
            ->where('q.created_at', '>=', self::SINCE)
            ->orderBy('r.nome')
            ->orderByDesc('q.created_at')
            ->orderByDesc('q.comissao_embarque_id')
            ->get(['q.embarque_id', 'q.tipo_comissao', 'q.tipo', 'q.valor_unitario', 'r.nome as responsavel', 'e.booking', 'm.name as moeda_nome', 'm.symbol as moeda_simbolo']);

        if ($commissions->isEmpty()) {
            return 0;
        }

        $containers = DB::table('container as ct')
            ->join('bl', 'bl.bl_id', '=', 'ct.bl_id')
            ->whereIn('bl.embarque_id', $commissions->pluck('embarque_id')->unique()->all())
            ->groupBy('bl.embarque_id')
            ->selectRaw('bl.embarque_id, count(*) as total')
            ->pluck('total', 'embarque_id');

        $list = '<table border=1 cellpadding=4 cellspacing=0>'
            .'<tr><th>Comissionado</th><th>Booking</th><th>Tipo de Comissão</th><th>Nº de Containers</th><th>tipo</th><th>Moeda</th><th>Valor Unitário</th></tr>'
            .$commissions->map(fn ($c) => '<tr>'
                .'<td>'.e($c->responsavel).'</td>'
                .'<td>'.e($c->booking).'</td>'
                .'<td>'.(ShippingCommissionsReport::COMMISSION_TYPES[(int) $c->tipo_comissao] ?? '').'</td>'
                .'<td>'.(int) ($containers[$c->embarque_id] ?? 0).'</td>'
                .'<td>'.($c->tipo == 1 ? 'Receber' : 'Pagar').'</td>'
                .'<td>'.e("{$c->moeda_nome} ({$c->moeda_simbolo})").'</td>'
                ."<td align='right'>".number_format((float) $c->valor_unitario, 2, ',', '.').'</td>'
                .'</tr>')->implode('')
            .'</table>';

        [$subject, $html] = app(RenderNoticeEmail::class)(Notice::OpenCommissions, ['data' => today()->format('d/m/Y'), 'lista' => $list]);
        app(SendNoticeMail::class)($subject, $html, Notice::OpenCommissions->recipients());

        return $commissions->count();
    }
}
