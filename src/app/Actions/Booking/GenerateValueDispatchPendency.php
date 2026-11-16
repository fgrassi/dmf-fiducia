<?php

namespace App\Actions\Booking;

use App\Actions\Reports\ValueDispatchPendenciesReport;
use Illuminate\Support\Facades\DB;

/**
 * updateConfirmacaoEmbarque (legado): ao confirmar o embarque, se o cliente tem "controlar envio de valores finais ao despachante"
 * (`pessoa.ctr_envio_valores` = 's') e a reserva ainda não tem controle, cria a pendência (status 1). Não duplica.
 */
class GenerateValueDispatchPendency
{
    public function __invoke(int $bookingId): bool
    {
        $controlled = DB::table('embarque as e')
            ->join('pessoa as p', 'p.pessoa_id', '=', 'e.cliente_id')
            ->where('e.embarque_id', $bookingId)
            ->where('p.ctr_envio_valores', 's')
            ->exists();

        if (! $controlled || DB::table('controle_envio_valores')->where('embarque_id', $bookingId)->exists()) {
            return false;
        }

        DB::table('controle_envio_valores')->insert(['embarque_id' => $bookingId, 'status' => ValueDispatchPendenciesReport::PENDING]);

        return true;
    }
}
