<?php

namespace App\Filament\Resources\Commissioned\Pages;

use App\Filament\Resources\Commissioned\CommissionedResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCommissioned extends ManageRecords
{
    protected static string $resource = CommissionedResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Novo Comissionado'),
        ];
    }
}
