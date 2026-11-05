<?php

namespace App\Filament\Resources\ContainerTypes\Pages;

use App\Filament\Resources\ContainerTypes\ContainerTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageContainerTypes extends ManageRecords
{
    protected static string $resource = ContainerTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Novo Tipo de Container'),
        ];
    }
}
