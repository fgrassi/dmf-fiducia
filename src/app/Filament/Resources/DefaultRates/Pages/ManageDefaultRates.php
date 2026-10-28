<?php

namespace App\Filament\Resources\DefaultRates\Pages;

use App\Filament\Resources\DefaultRates\DefaultRateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageDefaultRates extends ManageRecords
{
    protected static string $resource = DefaultRateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Incluir Nova Taxa Padrão'),
        ];
    }
}
