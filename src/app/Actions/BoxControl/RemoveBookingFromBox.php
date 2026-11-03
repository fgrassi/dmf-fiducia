<?php

namespace App\Actions\BoxControl;

use App\Models\ControleCaixa;
use App\Models\ControleCaixaEmbarque;
use Illuminate\Support\Facades\DB;

/**
 * removeEmbarque (legado): tira a reserva da caixa e reabre a caixa que ficou abaixo do limite.
 *
 * Diferença do legado: o limite é o da caixa (110 nas "Diversos"); o legado usava 50 para todas.
 */
class RemoveBookingFromBox
{
    public function __invoke(ControleCaixaEmbarque $item): void
    {
        DB::transaction(function () use ($item) {
            $box = ControleCaixa::findOrFail($item->controle_caixa_id);

            $item->delete();

            if ($box->bookings()->count() < $box->limit()) {
                $box->update(['status' => ControleCaixa::OPEN]);
            }
        });
    }
}
