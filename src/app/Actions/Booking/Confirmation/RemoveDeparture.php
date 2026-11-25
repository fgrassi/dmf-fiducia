<?php

namespace App\Actions\Booking\Confirmation;

use App\Models\Embarque;
use App\Models\Transborda;
use Illuminate\Support\Facades\DB;

/**
 * updateConfirmacaoEmbarque act=3 (legado): exclui uma mensagem de confirmação de saída. Se era a única, desfaz também a
 * `departure_confirmation`, a pendência de free hand e a saída efetiva. O controle PA volta a "Enviado" nos dois casos.
 */
class RemoveDeparture
{
    public function __invoke(Embarque $booking, Transborda $tracking): void
    {
        DB::transaction(function () use ($booking, $tracking) {
            $only = $booking->trackings()->whereHas('phrase', fn ($q) => $q->where('categoria_id', 10))->count() === 1;

            if ($only) {
                DB::table('departure_confirmation')->where('ref_embarque', $booking->getKey())->delete();
                DB::table('controle_free_hand')->where('embarque_id', $booking->getKey())->delete();
                DB::table('embarque')->where('embarque_id', $booking->getKey())->update(['saida_efetiva' => null]);
            }

            $tracking->delete();
            DB::table('controle_pa')->where('embarque_id', $booking->getKey())->update(['flag_fi_dmf' => 1]);
        });
    }
}
