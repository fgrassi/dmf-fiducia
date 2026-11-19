<?php

namespace App\Filament\Resources\TransitTimes\Pages;

use App\Filament\Resources\TransitTimes\TransitTimeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageTransitTimes extends ManageRecords
{
    protected static string $resource = TransitTimeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Novo Transit Time'),
        ];
    }
}
