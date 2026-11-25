<?php

namespace App\Actions\Booking\Confirmation;

use App\Actions\Booking\BookingLog;
use App\Models\Embarque;
use App\Models\Transborda;
use Illuminate\Support\Facades\DB;

/**
 * updateConfirmacaoChegada act=3 (legado): exclui uma mensagem de confirmação de chegada e registra a exclusão no
 * rastreamento. Se era a única, desfaz a `arrival_confirmation` e a chegada efetiva e, na importação, volta o status para Open.
 */
class RemoveArrival
{
    private const SUBJECT = 'Exclusão: Rastreamento';

    public function __construct(private BookingLog $log) {}

    public function __invoke(Embarque $booking, Transborda $tracking, int $userId): void
    {
        DB::transaction(function () use ($booking, $tracking, $userId) {
            $only = $booking->trackings()->whereHas('phrase', fn ($q) => $q->where('categoria_id', 11))->count() === 1;
            $history = 'A confirmação de chegada da reserva foi removida';

            if ($only) {
                $import = $booking->impor_expor === 'i';
                DB::table('arrival_confirmation')->where('ref_embarque', $booking->getKey())->delete();
                DB::table('embarque')->where('embarque_id', $booking->getKey())->update(['chegada_efetiva' => null] + ($import ? ['finalizado' => 'n'] : []));
                $history .= $import ? ", o status foi definido como 'Open' e data de chegada efetiva foi zerada" : ' e data de chegada efetiva foi zerada';
            }

            $tracking->delete();
            $this->log->track($booking->getKey(), self::SUBJECT, $history, $userId);
        });
    }
}
