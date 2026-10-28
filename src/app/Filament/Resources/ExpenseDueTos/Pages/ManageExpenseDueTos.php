<?php

namespace App\Filament\Resources\ExpenseDueTos\Pages;

use App\Filament\Resources\ExpenseDueTos\ExpenseDueToResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageExpenseDueTos extends ManageRecords
{
    protected static string $resource = ExpenseDueToResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
