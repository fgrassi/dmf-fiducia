<?php

namespace App\Filament\Resources\Carriers\Pages;

use App\Actions\Carriers\SaveCarrier;
use App\Filament\Resources\Carriers\CarrierResource;
use App\Models\Armador;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCarriers extends ManageRecords
{
    protected static string $resource = CarrierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Novo Armador')
                ->using(fn (array $data) => app(SaveCarrier::class)(new Armador, $data)),
        ];
    }
}
