<?php

namespace App\Actions\Booking\Bls;

use App\Models\Embarque;
use Illuminate\Support\Facades\DB;

/** avarias (legado): a observação de avaria de cada container da reserva, que sai na carta protesto. */
class SaveContainerDamages
{
    /** @param array<int|string, ?string> $damages container_id => observação */
    public function __invoke(Embarque $booking, array $damages): void
    {
        $mine = DB::table('container as c')->join('bl as b', 'b.bl_id', '=', 'c.bl_id')->where('b.embarque_id', $booking->getKey())->pluck('c.container_id')->all();

        foreach ($damages as $containerId => $text) {
            if (in_array((int) $containerId, $mine, true)) {
                DB::table('container')->where('container_id', $containerId)->update(['observacao_avaria' => filled($text) ? $text : null]);
            }
        }
    }
}
