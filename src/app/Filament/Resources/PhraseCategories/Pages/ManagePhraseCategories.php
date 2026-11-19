<?php

namespace App\Filament\Resources\PhraseCategories\Pages;

use App\Filament\Resources\PhraseCategories\PhraseCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManagePhraseCategories extends ManageRecords
{
    protected static string $resource = PhraseCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nova Categoria'),
        ];
    }
}
