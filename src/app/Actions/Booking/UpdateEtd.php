<?php

namespace App\Actions\Booking;

use App\Models\Embarque;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * atualizarEtd (legado): muda a saída prevista e empurra a chegada prevista mantendo o transit time (dias entre as
 * duas datas). Se já havia ETD, grava ETD e ETA no rastreamento e no histórico de campos. Só antes da confirmação de embarque.
 */
class UpdateEtd
{
    public function __construct(private BookingLog $log) {}

    /** @throws DomainException */
    public function __invoke(Embarque $booking, Carbon $etd, int $userId): void
    {
        throw_if($booking->departureConfirmations()->exists(), DomainException::class, 'O embarque já foi confirmado, não é possível alterar o ETD.');

        $oldEtd = $booking->saida_prevista ? Carbon::parse($booking->saida_prevista)->startOfDay() : null;
        $oldEta = $booking->chegada_prevista ? Carbon::parse($booking->chegada_prevista)->startOfDay() : null;
        $transit = $oldEtd && $oldEta ? $oldEtd->diffInDays($oldEta) : 0;
        $newEta = $etd->copy()->startOfDay()->addDays($transit);

        DB::transaction(function () use ($booking, $etd, $newEta, $oldEtd, $oldEta, $userId) {
            $booking->forceFill(['saida_prevista' => $etd->toDateString(), 'chegada_prevista' => $newEta->toDateString()])->save();

            if (! $oldEtd) {
                return;
            }

            foreach ([
                ['Saída Prevista', 'saida_prevista', $oldEtd, $etd],
                ['Chegada Prevista', 'chegada_prevista', $oldEta, $newEta],
            ] as [$label, $column, $old, $new]) {
                $this->log->track($booking->getKey(), "Atualizado: {$label}", "O Campo {$label} foi alterado de {$old?->format('d/m/Y')} para {$new->format('d/m/Y')}", $userId);
                $this->log->fieldChange('embarque', $column, $booking->getKey(), $old?->toDateString(), $new->toDateString(), $userId);
            }
        });
    }
}
