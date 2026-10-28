<?php

namespace App\Filament\Resources\ChargeTypes\Pages;

use App\Filament\Resources\ChargeTypes\ChargeTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageChargeTypes extends ManageRecords
{
    protected static string $resource = ChargeTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Incluir Novo Tipo de Cobrança'),
        ];
    }
}
