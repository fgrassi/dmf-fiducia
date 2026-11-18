<?php

namespace App\Actions\Financeiro;

use App\Actions\Notices\RenderNoticeEmail;
use App\Actions\Notices\SendNoticeMail;
use App\Enums\Notice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * cron/avisoFinanceiroVencimentoEnvioCambio (legado): agentes (pessoa.data_limite_cambio) cujo prazo para enviar o câmbio vence
 * de hoje a 5 dias, em um e-mail ao financeiro.
 */
class ExchangeDeadlineNotice
{
    /** @return int agentes avisados */
    public function __invoke(): int
    {
        if (! Notice::ExchangeDeadline->enabled()) {
            return 0;
        }

        $agents = DB::table('pessoa')
            ->whereBetween('data_limite_cambio', [today()->toDateString(), today()->addDays(5)->toDateString()])
            ->orderBy('data_limite_cambio')
            ->orderBy('pessoa_id')
            ->get(['nome', 'data_limite_cambio']);

        if ($agents->isEmpty()) {
            return 0;
        }

        $list = '<table border="1"><tr><th>Agente</th><th>Data Limite</th></tr>'
            .$agents->map(fn ($a) => '<tr><td>'.e($a->nome).'</td><td>'.date('d/m/Y', strtotime($a->data_limite_cambio)).'</td></tr>')->implode('')
            .'</table>';

        [$subject, $html] = app(RenderNoticeEmail::class)(Notice::ExchangeDeadline, ['lista' => $list]);
        app(SendNoticeMail::class)($subject, $html, Notice::ExchangeDeadline->recipients());

        return $agents->count();
    }
}
