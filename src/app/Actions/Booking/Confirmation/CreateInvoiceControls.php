<?php

namespace App\Actions\Booking\Confirmation;

use App\Models\Armador;
use App\Models\ControleInvoiceMaster;
use App\Models\Embarque;

/**
 * criaControleInvoiceMaster (legado): na primeira confirmação, um controle de invoice pendente por MBL da reserva marítima, se
 * o armador exige invoice para a operação da reserva. Não duplica (embarque + MBL). Sem linha de log, como o legado.
 */
class CreateInvoiceControls
{
    public function __invoke(Embarque $booking, int $userId): int
    {
        $carrier = $booking->tipo_embarque === 'm' ? Armador::find($booking->armador_id) : null;

        if (! $carrier || ! ($booking->impor_expor === 'i' ? $carrier->invoice_impo : $carrier->invoice_expo)) {
            return 0;
        }

        $created = 0;

        foreach ($booking->bls->pluck('mbl_number')->map(fn ($mbl) => trim((string) $mbl))->filter()->unique() as $mbl) {
            $control = ControleInvoiceMaster::firstOrCreate(
                ['embarque_id' => $booking->getKey(), 'mbl_number' => $mbl],
                ['armador_id' => $carrier->getKey(), 'status' => 0, 'created_at' => now(), 'created_by' => $userId],
            );

            $created += (int) $control->wasRecentlyCreated;
        }

        return $created;
    }
}
