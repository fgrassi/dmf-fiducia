<?php

namespace App\Actions\Booking\Tracking;

use App\Models\Transborda;

/** duplicarTracking (legado): copia a mensagem com a data de hoje e o usuário atual. Diferença do legado: não reenvia o e-mail. */
class DuplicateTracking
{
    public function __invoke(Transborda $tracking, int $userId): Transborda
    {
        $copy = $tracking->replicate();
        $copy->forceFill(['data_reg' => today()->toDateString(), 'usuario_id' => $userId])->save();

        return $copy;
    }
}
