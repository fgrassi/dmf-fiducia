<?php

namespace App\Actions\BoxControl;

use App\Models\ControleCaixa;
use App\Models\ControleCaixaEmbarque;
use App\Models\Embarque;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * incluirEmbarque (legado): guarda a reserva na caixa aberta, encerra a reserva (`finalizado = 'e'`) e fecha a caixa ao
 * chegar no limite.
 *
 * Diferença do legado: a reserva que já está em outra caixa é recusada sem mexer nela (o legado a marcava como encerrada
 * mesmo assim).
 */
class AddBookingToBox
{
    /** @throws DomainException */
    public function __invoke(ControleCaixa $box, int $bookingId, ?string $notes = null): ControleCaixaEmbarque
    {
        return DB::transaction(function () use ($box, $bookingId, $notes) {
            $box = ControleCaixa::lockForUpdate()->findOrFail($box->getKey());

            if ($box->status !== ControleCaixa::OPEN) {
                throw new DomainException('Caixa Cheia. Embarque Não Incluído.');
            }

            if (ControleCaixaEmbarque::where('embarque_id', $bookingId)->exists()) {
                throw new DomainException('Embarque já incluído em outra Caixa.');
            }

            $item = $box->bookings()->create(['embarque_id' => $bookingId, 'obs' => $notes]);

            Embarque::whereKey($bookingId)->update(['finalizado' => 'e']);

            if ($box->bookings()->count() >= $box->limit()) {
                $box->update(['status' => ControleCaixa::CLOSED]);
            }

            return $item;
        });
    }
}
