<?php

namespace App\Actions\Booking\Tracking;

use App\Models\Embarque;
use App\Models\Transborda;

/** duplicarTracking (legado): copia a mensagem com a data de hoje e o usuário atual e a reenvia por e-mail. */
class DuplicateTracking
{
    public function __construct(private SendTracking $send) {}

    /** @return bool false se o e-mail não saiu (sem contato ou com falha) */
    public function __invoke(Transborda $tracking, int $userId): bool
    {
        $copy = $tracking->replicate();
        $copy->forceFill(['data_reg' => today()->toDateString(), 'usuario_id' => $userId])->save();

        return ($this->send)(Embarque::findOrFail($copy->embarque_id), $copy, $userId);
    }
}
