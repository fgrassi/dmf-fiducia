<?php

namespace App\Filament\Resources\Assessorias\Pages;

use App\Filament\Resources\Assessorias\AssessoriaResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewAssessoria extends ViewRecord
{
    protected static string $resource = AssessoriaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
