<?php

namespace App\Actions\Booking;

use App\Actions\Notices\RenderNoticeEmail;
use App\Actions\Notices\SendNoticeMail;
use App\Enums\Notice;
use Illuminate\Support\Facades\DB;

/**
 * cron/relacaoReservasSemRevisao (legado): importações marítimas com as despesas não revisadas (a cobrança não é gerada) e
 * chegada prevista até daqui a 3 dias, inclusive as já vencidas; fora as canceladas.
 *
 * Diferença do legado: o e-mail só sai quando há reserva a revisar (o legado mandava "Nenhuma reserva encontrada").
 */
class UnreviewedExpensesNotice
{
    private const DAYS_AHEAD = 3;

    /** @return int reservas listadas */
    public function __invoke(): int
    {
        if (! Notice::UnreviewedExpenses->enabled()) {
            return 0;
        }

        $bookings = DB::table('embarque as e')
            ->leftJoin('pessoa as p', 'p.pessoa_id', '=', 'e.cliente_id')
            ->where('e.impor_expor', 'i')
            ->where('e.tipo_embarque', 'm')
            ->where('e.revisao_despesas', 'n')
            ->where('e.chegada_prevista', '<=', today()->addDays(self::DAYS_AHEAD)->toDateString())
            // fora as datas zeradas do banco legado
            ->where('e.chegada_prevista', '>', '0000-00-00')
            ->where('e.finalizado', '<>', 'c')
            ->orderBy('e.chegada_prevista')
            ->orderBy('e.embarque_id')
            ->get(['e.booking', 'e.chegada_prevista', 'p.nome as client']);

        if ($bookings->isEmpty()) {
            return 0;
        }

        $list = '<table border=1 cellpadding=4 cellspacing=0><tr><th>Reserva</th><th>Cliente</th><th>ETA</th></tr>'
            .$bookings->map(fn ($b) => '<tr><td>'.e($b->booking).'</td><td>'.e($b->client).'</td><td>'.date('d/m/Y', strtotime($b->chegada_prevista)).'</td></tr>')->implode('')
            .'</table>';

        [$subject, $html] = app(RenderNoticeEmail::class)(Notice::UnreviewedExpenses, ['lista' => $list]);
        app(SendNoticeMail::class)($subject, $html, Notice::UnreviewedExpenses->recipients());

        return $bookings->count();
    }
}
