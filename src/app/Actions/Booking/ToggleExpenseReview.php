<?php

namespace App\Actions\Booking;

use App\Models\Embarque;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * mudaStatusRevisao + verificaDueTo (legado): alterna o controle de revisão de despesas da reserva (revisao_despesas s/n),
 * que a geração da cobrança exige nas importações marítimas. Marcar como revisada só vale se todas as despesas tiverem Due To.
 * Registra no rastreamento antes de alternar.
 */
class ToggleExpenseReview
{
    /** Só importação marítima tem o controle (embarqueExpensesSuccess.php). */
    public static function applies(Embarque $booking): bool
    {
        return $booking->impor_expor === 'i' && $booking->tipo_embarque === 'm';
    }

    /**
     * @return bool a reserva ficou revisada
     *
     * @throws DomainException
     */
    public function __invoke(Embarque $booking, int $userId): bool
    {
        $reviewed = $booking->revisao_despesas === 's';

        if (! $reviewed && $booking->expenses()->whereNull('ref_due_to')->exists()) {
            throw new DomainException('Todas as despesas necessitam estar com o campo "Due To" informado!');
        }

        DB::transaction(function () use ($booking, $reviewed, $userId) {
            DB::table('rastreamento')->insert([
                'assunto' => 'Atualizado: Revisão de despesas',
                'evento' => 'x',
                'historico' => '<h3>Dados</h3> <p>Controle de revisão de despesas: status alerado para '.($reviewed ? 'Não Revisado' : 'Revisado').'</p>',
                'ref_embarque' => $booking->getKey(),
                'created_at' => now()->toDateString(),
                'created_time' => now()->format('H:i:s'),
                'created_by' => $userId,
            ]);

            $booking->forceFill(['revisao_despesas' => $reviewed ? 'n' : 's'])->save();
        });

        return ! $reviewed;
    }
}
