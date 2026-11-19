<?php

namespace App\Actions\Booking\Schedule;

use App\Models\Embarque;
use App\Models\LiberacaoEmbarqueOrigem;

/** saveEnvioDoc (legado): grava a data de envio dos documentos à origem, marca como enviado e registra quem e quando. */
class SaveOriginDocs
{
    public function __invoke(Embarque $booking, string $date, int $userId, ?LiberacaoEmbarqueOrigem $release = null): LiberacaoEmbarqueOrigem
    {
        $attributes = [
            'embarque_id' => $booking->getKey(),
            'data_liberacao' => $date,
            'status' => LiberacaoEmbarqueOrigem::SENT,
            'updated_by' => $userId,
            'updated_at' => now(),
        ];

        return $release ? tap($release)->update($attributes) : LiberacaoEmbarqueOrigem::create($attributes);
    }
}
