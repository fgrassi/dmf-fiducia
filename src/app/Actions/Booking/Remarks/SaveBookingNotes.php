<?php

namespace App\Actions\Booking\Remarks;

use App\Actions\Booking\BookingLog;
use App\Models\Embarque;
use Illuminate\Support\Facades\DB;

/**
 * saveEmbarqueObs (legado): grava a observação "especial" do embarque (`embarque_obs`, que sai na instrução de cobrança).
 * O histórico de quem alterou fica em `rastreabilidade_alteracao`, que o legado mostra na tela de observações.
 */
class SaveBookingNotes
{
    public function __construct(private BookingLog $log) {}

    public function __invoke(Embarque $booking, ?string $notes, int $userId): void
    {
        $old = $booking->embarque_obs;
        $notes = filled($notes) ? str_replace("\r\n", "\n", $notes) : null;

        if ($old === $notes) {
            return;
        }

        DB::transaction(function () use ($booking, $notes, $old, $userId) {
            $booking->forceFill(['embarque_obs' => $notes])->save();
            $this->log->fieldChange('embarque', 'embarque_obs', $booking->getKey(), $old, $notes, $userId);
        });
    }
}
