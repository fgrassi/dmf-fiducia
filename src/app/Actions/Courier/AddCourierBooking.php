<?php

namespace App\Actions\Courier;

use App\Models\ControleCourier;
use App\Models\CtrCourierEmbarque;
use DomainException;

/**
 * incluirEmbarque (legado): liga uma reserva ao courier.
 *
 * Diferença do legado: ele só escondia o formulário do courier com nota vinculada; aqui a ação recusa (a despesa do agente
 * já nasceu das reservas que estavam ligadas). Como no legado, a mesma reserva pode ser ligada mais de uma vez.
 *
 * @throws DomainException
 */
class AddCourierBooking
{
    public function __invoke(ControleCourier $courier, int $bookingId, ?string $note): CtrCourierEmbarque
    {
        throw_if($courier->isLinked(), DomainException::class, 'Courier com nota vinculada não aceita novas reservas.');

        return $courier->bookings()->create(['embarque_id' => $bookingId, 'observacao' => filled($note) ? $note : null]);
    }
}
