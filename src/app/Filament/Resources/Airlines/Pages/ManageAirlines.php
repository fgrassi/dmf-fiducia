<?php

namespace App\Filament\Resources\Airlines\Pages;

use App\Filament\Resources\Airlines\AirlineResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageAirlines extends ManageRecords
{
    protected static string $resource = AirlineResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nova Cia Aérea'),
        ];
    }
}
