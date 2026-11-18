<?php

namespace App\Actions\Financeiro;

use App\Actions\Notices\RenderNoticeEmail;
use App\Actions\Notices\SendNoticeMail;
use App\Enums\Notice;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * cron/relatorioStatusNotas (legado): todo dia 1º, a Marcia recebe as reservas (nem cancelada nem finalizada) que passaram a
 * NÃO precisar de nota de crédito/débito no mês anterior (controle_credit / controle_debit preenchidos naquele período).
 * Sem alteração no mês, o e-mail sai igual, dizendo isso.
 *
 * Diferença do legado: o último dia do mês entra inteiro. O legado comparava com a data pura (31/10 = 31/10 00:00) e perdia o que
 * foi alterado nesse dia depois da meia-noite.
 */
class NotesControlMonthlyReport
{
    /** @return int reservas listadas (0 fora do dia 1º, sem e-mail) */
    public function __invoke(): int
    {
        if (! Notice::NotesMonthlyReport->enabled()) {
            return 0;
        }

        if (today()->day !== 1) {
            return 0;
        }

        $from = today()->subMonthNoOverflow()->startOfMonth();
        $to = $from->copy()->endOfMonth();

        $bookings = DB::table('embarque')
            ->where(fn ($q) => $q
                ->whereBetween('controle_credit', [$from, $to])
                ->orWhereBetween('controle_debit', [$from, $to]))
            ->whereNotIn('finalizado', ['c', 'e'])
            ->orderBy('embarque_id')
            ->get(['booking', 'controle_credit', 'controle_debit']);

        $period = $from->format('d/m/Y').' a '.$to->format('d/m/Y');
        $list = $bookings->isEmpty()
            ? 'Nenhuma alteração realizada.'
            : "<p>Relatório de reservas que NÃO necessitam de notas e tiveram seu status alterado no período de {$period}.</p>"
                .'<table border=1><tr><th>Reserva</th><th>Data da Alteração</th></tr>'
                .$bookings->map(fn ($b) => '<tr><td>'.e($b->booking).'</td><td><ul>'
                    .($b->controle_credit ? '<li>CN - '.Carbon::parse($b->controle_credit)->format('d/m/Y H:i').'</li>' : '')
                    .($b->controle_debit ? '<li>DN - '.Carbon::parse($b->controle_debit)->format('d/m/Y H:i').'</li>' : '')
                    .'</ul></td></tr>')->implode('')
                .'</table>';

        $month = mb_strtoupper($from->copy()->locale('pt_BR')->translatedFormat('F'));

        [$subject, $html] = app(RenderNoticeEmail::class)(Notice::NotesMonthlyReport, ['mes' => $month, 'ano' => $from->year, 'lista' => $list]);
        app(SendNoticeMail::class)($subject, $html, Notice::NotesMonthlyReport->recipients());

        return $bookings->count();
    }
}
