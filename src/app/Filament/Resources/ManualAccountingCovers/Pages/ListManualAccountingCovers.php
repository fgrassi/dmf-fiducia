<?php

namespace App\Filament\Resources\ManualAccountingCovers\Pages;

use App\Actions\AccountingCovers\SaveManualCover;
use App\Filament\Actions\NewCoverFolderAction;
use App\Filament\Resources\ManualAccountingCovers\ManualAccountingCoverResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListManualAccountingCovers extends ListRecords
{
    protected static string $resource = ManualAccountingCoverResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nova capa manual')
                ->using(fn (array $data, SaveManualCover $save) => $save($data, userId: auth()->user()?->getAuthIdentifier())),
            NewCoverFolderAction::make(),
        ];
    }
}
