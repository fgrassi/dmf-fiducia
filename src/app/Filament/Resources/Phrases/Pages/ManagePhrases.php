<?php

namespace App\Filament\Resources\Phrases\Pages;

use App\Filament\Resources\Phrases\PhraseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManagePhrases extends ManageRecords
{
    protected static string $resource = PhraseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nova Frase'),
        ];
    }
}
