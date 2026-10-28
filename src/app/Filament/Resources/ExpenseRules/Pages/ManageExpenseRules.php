<?php

namespace App\Filament\Resources\ExpenseRules\Pages;

use App\Filament\Resources\ExpenseRules\ExpenseRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageExpenseRules extends ManageRecords
{
    protected static string $resource = ExpenseRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Adicionar Regra'),
        ];
    }
}
