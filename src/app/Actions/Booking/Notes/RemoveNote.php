<?php

namespace App\Actions\Booking\Notes;

use App\Enums\NoteType;
use Illuminate\Support\Facades\DB;

/** executeRemoveDebitNote / executeRemoveCreditNote (legado): apaga a nota e os itens e registra a justificativa no rastreamento. */
class RemoveNote
{
    public function __construct(private NoteTracking $tracking) {}

    public function __invoke(NoteType $type, int $noteId, string $justification, int $userId): void
    {
        DB::transaction(function () use ($type, $noteId, $justification, $userId) {
            // O histórico lê a nota, então é gravado antes de apagá-la.
            $this->tracking->note($type, $noteId, 'd', $userId, $justification);

            DB::table($type->itemsTable())->where($type->itemColumn(), $noteId)->delete();
            DB::table($type->table())->where('id', $noteId)->delete();
        });
    }
}
