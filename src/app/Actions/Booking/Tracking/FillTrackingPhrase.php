<?php

namespace App\Actions\Booking\Tracking;

use App\Models\Embarque;
use Illuminate\Support\Facades\DB;

/**
 * replaceFrasesTracking (legado): troca os marcadores da frase pelos dados da reserva, e deixa o marcador quando o dado
 * não existe. Diferença do legado: sem `eval`, só os seis marcadores conhecidos.
 */
class FillTrackingPhrase
{
    public function __invoke(Embarque $booking, string $text): string
    {
        $port = fn (?int $id) => $id ? DB::table('porto')->where('porto_id', $id)->value('nome') : null;
        $ship = fn (?int $id) => $id ? DB::table('navio')->where('navio_id', $id)->value('nome') : null;

        return strtr($text, [
            '{NAVIO}' => $ship($booking->navio_id) ?: '{NAVIO}',
            '{NAVIO_FINAL}' => $ship($booking->navio_id_destino) ?: '{NAVIO_FINAL}',
            '{VIAGEM}' => $booking->vg ?: '{VIAGEM}',
            '{VIA}' => $booking->via ?: '{VIA}',
            '{PORTO_ORIGEM}' => $port($booking->porto_origem_id) ?: '{PORTO_ORIGEM}',
            '{PORTO_DESTINO}' => $port($booking->porto_destino_id) ?: '{PORTO_DESTINO}',
        ]);
    }
}
