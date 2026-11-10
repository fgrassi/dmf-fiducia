<?php

namespace App\Actions\Courier;

use App\Models\CtrCourierEmbarque;
use DomainException;

/**
 * removeEmbarque (legado): desliga a reserva do courier. Recusa se o courier já tem nota vinculada, como o legado, que
 * escondia o botão.
 *
 * @throws DomainException
 */
class RemoveCourierBooking
{
    public function __invoke(CtrCourierEmbarque $booking): void
    {
        throw_if($booking->courier->isLinked(), DomainException::class, 'Courier com nota vinculada não aceita remover reservas.');

        $booking->delete();
    }
}
