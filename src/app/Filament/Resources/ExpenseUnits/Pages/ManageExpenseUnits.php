<?php

namespace App\Filament\Resources\ExpenseUnits\Pages;

use App\Filament\Resources\ExpenseUnits\ExpenseUnitResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageExpenseUnits extends ManageRecords
{
    protected static string $resource = ExpenseUnitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Incluir Nova Unidade'),
        ];
    }
}
