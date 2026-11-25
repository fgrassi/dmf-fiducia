<?php

namespace App\Actions\Booking\Confirmation;

use App\Models\Embarque;
use Illuminate\Support\Facades\DB;

/** Mensagem interna (família "favoritos") aos usuários que favoritaram a reserva, quando ela é confirmada. */
class NotifyFavorites
{
    private const FAVORITES_FAMILY = 5;

    public function __invoke(Embarque $booking, string $subject, string $message, int $userId): void
    {
        $recipients = DB::table('embarque_favorito')->where('embarque_id', $booking->getKey())->pluck('usuario_id');

        if ($recipients->isEmpty()) {
            return;
        }

        $messageId = DB::table('cmn_messages')->insertGetId([
            'ref_family' => self::FAVORITES_FAMILY,
            'author' => $userId,
            'created_at' => now(),
            'subject' => $subject,
            'message' => $message,
        ]);

        DB::table('cmn_messages_recipients')->insert($recipients->map(fn ($id) => ['ref_message' => $messageId, 'recipient' => $id, 'status' => 1])->all());
    }
}
