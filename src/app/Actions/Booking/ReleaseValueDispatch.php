<?php

namespace App\Actions\Booking;

use App\Actions\Reports\ValueDispatchPendenciesReport;
use Illuminate\Support\Facades\DB;

/** executePendenciaEnvioValores (legado): libera o envio de valores finais ao despachante (`controle_envio_valores`.status 1 → 2). */
class ReleaseValueDispatch
{
    public function __invoke(int $bookingId, int $userId): void
    {
        DB::table('controle_envio_valores')->where('embarque_id', $bookingId)->where('status', ValueDispatchPendenciesReport::PENDING)->update([
            'status' => ValueDispatchPendenciesReport::RELEASED,
            'updated_by' => $userId,
            'updated_at' => now()->toDateTimeString(),
        ]);
    }
}
