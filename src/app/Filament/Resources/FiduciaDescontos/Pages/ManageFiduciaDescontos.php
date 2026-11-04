<?php

namespace App\Filament\Resources\FiduciaDescontos\Pages;

use App\Filament\Resources\FiduciaDescontos\FiduciaDescontoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageFiduciaDescontos extends ManageRecords
{
    protected static string $resource = FiduciaDescontoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Adicionar Desconto'),
        ];
    }
}
