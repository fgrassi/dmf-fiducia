<?php

namespace App\Actions\Booking\Notes;

use App\Enums\NoteType;
use Illuminate\Support\Facades\DB;

/** executeSaveDebitNote / executeSaveCreditNote (legado): grava os valores digitados na nota e registra o rastreamento. */
class UpdateNote
{
    public function __construct(private NoteTracking $tracking) {}

    /** @param  array<string, mixed>  $data  obs, dolar_rate, due_to_*, exw_expenses e, no débito, handling, origin_comission e data_vencimento */
    public function __invoke(NoteType $type, int $noteId, array $data, int $userId): void
    {
        DB::transaction(function () use ($type, $noteId, $data, $userId) {
            $bookingId = DB::table($type->table())->where('id', $noteId)->value('ref_embarque');

            // O débito salvo sempre volta para o agente atual da reserva (o legado sobrescrevia o campo).
            $agent = $type === NoteType::Debit ? DB::table('embarque')->where('embarque_id', $bookingId)->value('pessoa_id_representante') : null;

            DB::table($type->table())->where('id', $noteId)->update([...$data, ...($agent ? ['ref_agente' => $agent] : [])]);

            $this->tracking->note($type, $noteId, 'u', $userId);
        });
    }
}
