<?php

namespace App\Actions\Booking\Creation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** HistEmbarqueTable::registrarSnapshot (legado): a Nutrire lê o MAX(nquando) para saber quando a DMF mudou as datas. */
class HistorySnapshot
{
    public function __invoke(int $bookingId, int $userId, string $operation = 'I'): void
    {
        $booking = (array) DB::table('embarque')->where('embarque_id', $bookingId)->first();
        $row = array_intersect_key($booking, array_flip(Schema::getColumnListing('hist_embarque')));

        DB::table('hist_embarque')->insert([
            ...$row,
            'despachante_id' => $booking['despachante_id'] ?? 0,
            'nsequencia' => (int) DB::table('hist_embarque')->where('embarque_id', $bookingId)->max('nsequencia') + 1,
            'nquando' => now()->toDateTimeString(),
            'noperacao' => $operation,
            'nusuario_id' => $userId,
        ]);
    }
}
