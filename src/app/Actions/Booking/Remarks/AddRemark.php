<?php

namespace App\Actions\Booking\Remarks;

use App\Models\ObservacaoEmbarque;

/** addObservacaoEmbarque (legado): lembrete/observação livre da reserva. */
class AddRemark
{
    public function __invoke(int $bookingId, string $message, int $userId): ObservacaoEmbarque
    {
        return ObservacaoEmbarque::create([
            'embarque_id' => $bookingId,
            'mensagem' => $message,
            'created_by' => $userId,
            'created_at' => now()->toDateTimeString(),
        ]);
    }
}
