<?php

namespace App\Filament\Resources\Embarques\RelationManagers;

use App\Enums\NoteType;

class DebitNotesRelationManager extends NotesRelationManager
{
    protected static string $relationship = 'debitNotes';

    protected static ?string $title = 'Debit Note';

    protected function type(): NoteType
    {
        return NoteType::Debit;
    }
}
