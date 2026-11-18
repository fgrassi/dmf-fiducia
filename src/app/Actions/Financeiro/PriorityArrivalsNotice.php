<?php

namespace App\Actions\Financeiro;

use App\Actions\Notices\RenderNoticeEmail;
use App\Actions\Notices\SendNoticeMail;
use App\Enums\Notice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * cron/avisoFinanceiroEmbarquesPrioritarios (legado): reservas prioritárias (flag_prioritario) não canceladas que chegam
 * exatamente daqui a 5 dias e, em outro e-mail, daqui a 2 dias, com o equipamento de cada uma.
 */
class PriorityArrivalsNotice
{
    private const DAYS = [5, 2];

    /** @return int reservas avisadas nos dois e-mails */
    public function __invoke(): int
    {
        if (! Notice::PriorityArrivals->enabled()) {
            return 0;
        }

        return collect(self::DAYS)->sum(fn (int $days) => $this->notify($days));
    }

    private function notify(int $days): int
    {
        $bookings = DB::table('embarque as e')
            ->leftJoin('pessoa as p', 'p.pessoa_id', '=', 'e.cliente_id')
            ->whereDate('e.chegada_prevista', today()->addDays($days)->toDateString())
            ->where('e.flag_prioritario', 1)
            ->where('e.finalizado', '<>', 'c')
            ->orderBy('e.embarque_id')
            ->get(['e.embarque_id', 'e.booking', 'e.chegada_prevista', 'p.nome', 'p.cnpj']);

        if ($bookings->isEmpty()) {
            return 0;
        }

        $equipment = DB::table('mercadoria as m')
            ->leftJoin('tipo_container as t', 't.tipo_container_id', '=', 'm.tipo_container_id')
            ->whereIn('m.embarque_id', $bookings->pluck('embarque_id')->all())
            ->orderBy('m.mercadoria_id')
            ->get(['m.embarque_id', 'm.quantidade', 't.nome as tipo'])
            ->groupBy('embarque_id');

        $list = '<table border="1"><tr><th>Reserva</th><th>Cliente</th><th>ETA</th><th>Equipamento</th></tr>'
            .$bookings->map(fn ($b) => '<tr><td>'.e($b->booking).'</td>'
                .'<td>'.e($b->nome ? "{$b->nome} ({$b->cnpj})" : '').'</td>'
                .'<td>'.date('d/m/Y', strtotime($b->chegada_prevista)).'</td>'
                .'<td><ul>'.$equipment->get($b->embarque_id, collect())->map(fn ($m) => '<li>'.e("{$m->quantidade}x{$m->tipo}").'</li>')->implode('').'</ul></td></tr>')->implode('')
            .'</table>';

        [$subject, $html] = app(RenderNoticeEmail::class)(Notice::PriorityArrivals, ['dias' => $days, 'lista' => $list]);
        app(SendNoticeMail::class)($subject, $html, Notice::PriorityArrivals->recipients());

        return $bookings->count();
    }
}
