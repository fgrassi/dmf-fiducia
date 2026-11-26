<?php

namespace App\Actions\Booking;

use App\Models\Embarque;
use Illuminate\Support\Facades\DB;

/** Embarque::checkVendedor (legado): o vendedor pode receber o tracking da reserva? */
class SellerRestriction
{
    /**
     * Reservas antigas e vendedores sem restrição podem; com restrição, só se o cliente é dele ou a reserva foi autorizada.
     * Como no legado, a reserva sem cadastro de autorização passa a ter a restrição pendente.
     */
    public function __invoke(Embarque $booking): bool
    {
        if (($booking->data_topo ?? '') < '2015-01-05' || ! DB::table('vendedor_restricao')->where('vendedor_id', $booking->vendedor_id)->exists()) {
            return true;
        }

        if (DB::table('vendedor_pessoa')->where('vendedor_id', $booking->vendedor_id)->where('pessoa_id', $booking->cliente_id)->exists()) {
            return true;
        }

        $restriction = DB::table('vendedor_restricao_embarque')->where('embarque_id', $booking->getKey())->orderBy('vendedor_restricao_embarque_id')->first();

        if ($restriction) {
            return (int) $restriction->status !== 0;
        }

        DB::table('vendedor_restricao_embarque')->insert(['embarque_id' => $booking->getKey(), 'vendedor_id' => $booking->vendedor_id, 'status' => 0]);

        return false;
    }
}
