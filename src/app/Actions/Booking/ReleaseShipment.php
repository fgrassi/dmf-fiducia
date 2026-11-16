<?php

namespace App\Actions\Booking;

use Illuminate\Support\Facades\DB;

/** liberaEmbarque (legado): dá baixa na pendência de liberação do embarque (`liberacao_embarque`.status = 1). */
class ReleaseShipment
{
    public function __invoke(int $bookingId, int $userId): void
    {
        DB::table('liberacao_embarque')->where('embarque_id', $bookingId)->where('status', 0)->update([
            'status' => 1,
            'updated_by' => $userId,
            'updated_at' => now()->toDateTimeString(),
        ]);
    }
}
