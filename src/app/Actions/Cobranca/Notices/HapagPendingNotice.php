<?php

namespace App\Actions\Cobranca\Notices;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * cron/avisoCobrancaHapag (legado): exportações Hapag (armador 19) com saída confirmada e ctr_desp = 1. A que não tem mais
 * despesa a cobrar (billing p, venda > 0, fora de IC) volta para ctr_desp = 0; as que sobram vão por e-mail ao financeiro.
 */
class HapagPendingNotice
{
    private const HAPAG = 19;

    /** @return int reservas pendentes avisadas */
    public function __invoke(): int
    {
        $pending = fn () => DB::table('embarque as e')
            ->where('e.impor_expor', 'e')
            ->where('e.ctr_desp', 1)
            ->where('e.finalizado', '<>', 'c')
            ->where('e.armador_id', self::HAPAG)
            ->whereExists(fn ($q) => $q->from('departure_confirmation as dc')->whereColumn('dc.ref_embarque', 'e.embarque_id'))
            ->leftJoin('pessoa as p', 'p.pessoa_id', '=', 'e.cliente_id')
            ->orderBy('e.embarque_id')
            ->get(['e.embarque_id', 'e.booking', 'p.nome']);

        foreach ($pending() as $b) {
            $hasExpenses = DB::table('fin_expenses_values')
                ->where('ref_embarque', $b->embarque_id)
                ->where('billing', 'p')
                ->where('selling_rate', '>', 0)
                ->whereNotIn('id', DB::table('fin_charges_items')->whereNotNull('ref_expense')->select('ref_expense'))
                ->exists();

            if (! $hasExpenses) {
                DB::table('embarque')->where('embarque_id', $b->embarque_id)->update(['ctr_desp' => 0]);
            }
        }

        $bookings = $pending();

        if ($bookings->isEmpty()) {
            return 0;
        }

        $html = '<p>As seguintes reservas estão pendentes para serem geradas as cobranças</p>'
            .$bookings->map(fn ($b) => '<ul><li>'.e("{$b->booking} - {$b->nome}").'</li></ul>')->implode('');

        Mail::html($html, fn ($mail) => $mail
            ->to(['financeiro@dmflog.com.br', 'it@dmflog.com.br'])
            ->subject('[FINANCEIRO] Reservas da Hapag pendentes de cobrança - EXPO'));

        return $bookings->count();
    }
}
