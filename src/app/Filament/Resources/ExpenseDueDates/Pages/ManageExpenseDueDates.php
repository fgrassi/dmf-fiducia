<?php

namespace App\Filament\Resources\ExpenseDueDates\Pages;

use App\Filament\Resources\ExpenseDueDates\ExpenseDueDateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageExpenseDueDates extends ManageRecords
{
    protected static string $resource = ExpenseDueDateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Novo'),
        ];
    }
}
