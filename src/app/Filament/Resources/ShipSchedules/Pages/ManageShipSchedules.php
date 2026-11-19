<?php

namespace App\Filament\Resources\ShipSchedules\Pages;

use App\Filament\Resources\ShipSchedules\ShipScheduleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageShipSchedules extends ManageRecords
{
    protected static string $resource = ShipScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nova Programação')
                ->mutateDataUsing(fn (array $data) => [...$data, 'created_by' => auth()->id(), 'created_at' => now()]),
        ];
    }
}
