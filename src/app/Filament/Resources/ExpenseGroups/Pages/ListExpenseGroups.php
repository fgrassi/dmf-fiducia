<?php

namespace App\Filament\Resources\ExpenseGroups\Pages;

use App\Filament\Resources\ExpenseGroups\ExpenseGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListExpenseGroups extends ListRecords
{
    protected static string $resource = ExpenseGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Incluir Novo Grupo'),
        ];
    }
}
