<?php

namespace App\Filament\Resources\Embarques\RelationManagers;

use App\Enums\NoteType;

class CreditNotesRelationManager extends NotesRelationManager
{
    protected static string $relationship = 'creditNotes';

    protected static ?string $title = 'Credit Note';

    protected function type(): NoteType
    {
        return NoteType::Credit;
    }
}
