<?php

namespace App\Actions\Booking\Notes;

use App\Enums\NoteType;
use DomainException;
use Illuminate\Support\Facades\DB;

/** executeAddCreditNoteItem / AddDebitNoteItem / RemoveCreditNoteItem / RemoveDebitNoteItem (legado): inclui ou tira uma despesa e recalcula a nota. */
class ChangeNoteItems
{
    public function __construct(private RecalculateNote $recalculate) {}

    /** @throws DomainException */
    public function add(NoteType $type, int $noteId, int $expenseId): void
    {
        $bookingId = DB::table($type->table())->where('id', $noteId)->value('ref_embarque');

        if (! DB::table('fin_expenses_values')->where('id', $expenseId)->where('ref_embarque', $bookingId)->exists()) {
            throw new DomainException('A despesa não pertence a esta reserva.');
        }

        if (DB::table($type->itemsTable())->where($type->itemColumn(), $noteId)->where('ref_expense', $expenseId)->exists()) {
            throw new DomainException('Esta despesa já está na nota.');
        }

        DB::transaction(function () use ($type, $noteId, $expenseId) {
            DB::table($type->itemsTable())->insert([$type->itemColumn() => $noteId, 'ref_expense' => $expenseId]);
            ($this->recalculate)($type, $noteId);
        });
    }

    public function remove(NoteType $type, int $noteId, int $expenseId): void
    {
        DB::transaction(function () use ($type, $noteId, $expenseId) {
            DB::table($type->itemsTable())->where($type->itemColumn(), $noteId)->where('ref_expense', $expenseId)->delete();
            ($this->recalculate)($type, $noteId);
        });
    }
}
