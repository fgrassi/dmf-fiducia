<?php

namespace App\Filament\Resources\CoverFolders\Pages;

use App\Filament\Actions\NewCoverFolderAction;
use App\Filament\Resources\CoverFolders\CoverFolderResource;
use Filament\Resources\Pages\ManageRecords;

class ManageCoverFolders extends ManageRecords
{
    protected static string $resource = CoverFolderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            NewCoverFolderAction::make(),
        ];
    }
}
