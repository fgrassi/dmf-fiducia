<?php

namespace App\Actions\Booking\Creation;

use DomainException;
use Illuminate\Support\Facades\DB;

/** booking/getBooking (legado): número da reserva de importação, com o prefixo da sede do vendedor e a sequência única; no aéreo, com "A" na frente. */
class NextBookingNumber
{
    /**
     * @param  'm'|'a'  $mode
     *
     * @throws DomainException
     */
    public function __invoke(int $sellerId, string $mode): string
    {
        $prefix = DB::table('vendedor as v')->join('sedes as s', 's.id', '=', 'v.sede_id')->where('v.vendedor_id', $sellerId)->value('s.prefixo_imp');

        throw_if($prefix === null, DomainException::class, 'O vendedor não tem sede com prefixo de importação cadastrado.');

        return DB::transaction(function () use ($prefix, $mode) {
            $sequence = (int) DB::table('import_booking')->lockForUpdate()->max('sequencia') + 1;
            DB::table('import_booking')->insert(['sequencia' => $sequence]);

            return $this->modePrefix($mode).$prefix.$sequence.'-'.now()->format('y');
        });
    }

    /**
     * O número tem o formato do modal, com o prefixo de alguma sede (o vendedor pode ter sido trocado depois de pedir o número),
     * e a sequência foi emitida.
     *
     * @param  'm'|'a'  $mode
     */
    public function wasIssued(string $number, string $mode): bool
    {
        return DB::table('sedes')->where('prefixo_imp', '<>', '')->distinct()->pluck('prefixo_imp')->contains(
            fn (string $prefix) => preg_match('/^'.preg_quote($this->modePrefix($mode).$prefix, '/').'(\d+)-\d{2}$/D', $number, $match)
                && DB::table('import_booking')->where('sequencia', $match[1])->exists(),
        );
    }

    private function modePrefix(string $mode): string
    {
        return $mode === 'a' ? 'A' : '';
    }
}
