<?php

namespace App\Actions\Booking\Creation;

use DomainException;
use Illuminate\Support\Facades\DB;

/** booking/getBooking (legado): número da reserva de importação, com o prefixo da sede do vendedor e a sequência única. */
class NextBookingNumber
{
    /** @throws DomainException */
    public function __invoke(int $sellerId): string
    {
        $prefix = DB::table('vendedor as v')->join('sedes as s', 's.id', '=', 'v.sede_id')->where('v.vendedor_id', $sellerId)->value('s.prefixo_imp');

        throw_if($prefix === null, DomainException::class, 'O vendedor não tem sede com prefixo de importação cadastrado.');

        return DB::transaction(function () use ($prefix) {
            $sequence = (int) DB::table('import_booking')->lockForUpdate()->max('sequencia') + 1;
            DB::table('import_booking')->insert(['sequencia' => $sequence]);

            return $prefix.$sequence.'-'.now()->format('y');
        });
    }
}
