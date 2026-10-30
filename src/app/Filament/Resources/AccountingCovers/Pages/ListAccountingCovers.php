<?php

namespace App\Filament\Resources\AccountingCovers\Pages;

use App\Filament\Actions\NewCoverAction;
use App\Filament\Actions\NewCoverFolderAction;
use App\Filament\Resources\AccountingCovers\AccountingCoverResource;
use Filament\Resources\Pages\ListRecords;

class ListAccountingCovers extends ListRecords
{
    protected static string $resource = AccountingCoverResource::class;

    protected function getHeaderActions(): array
    {
        return [
            NewCoverAction::make(),
            NewCoverFolderAction::make(),
        ];
    }
}
