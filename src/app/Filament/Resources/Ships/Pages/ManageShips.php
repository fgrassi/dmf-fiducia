<?php

namespace App\Filament\Resources\Ships\Pages;

use App\Filament\Resources\Ships\ShipResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageShips extends ManageRecords
{
    protected static string $resource = ShipResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Novo Navio'),
        ];
    }
}
