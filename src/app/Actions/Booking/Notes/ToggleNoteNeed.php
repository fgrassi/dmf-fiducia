<?php

namespace App\Actions\Booking\Notes;

use App\Enums\NoteType;
use Illuminate\Support\Facades\DB;

/** executeEditStatusDebit / executeEditStatusCredit + SaveJustificativa (legado): marca ou desmarca "não precisa de nota" na reserva, com justificativa. */
class ToggleNoteNeed
{
    public function __construct(private NoteTracking $tracking) {}

    /** @return bool a reserva ficou marcada como sem necessidade de nota */
    public function __invoke(NoteType $type, int $bookingId, string $justification, int $userId): bool
    {
        return DB::transaction(function () use ($type, $bookingId, $justification, $userId) {
            $current = DB::table('embarque')->where('embarque_id', $bookingId)->value($type->needColumn());
            $marked = blank($current) || $current === '0000-00-00 00:00:00';

            DB::table('embarque')->where('embarque_id', $bookingId)->update([$type->needColumn() => $marked ? now()->toDateTimeString() : null]);

            $this->tracking->status($type, $bookingId, $userId, $justification);

            return $marked;
        });
    }
}
