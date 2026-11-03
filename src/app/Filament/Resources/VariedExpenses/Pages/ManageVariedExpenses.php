<?php

namespace App\Filament\Resources\VariedExpenses\Pages;

use App\Filament\Resources\VariedExpenses\VariedExpenseResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageVariedExpenses extends ManageRecords
{
    protected static string $resource = VariedExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nova Nota')
                ->modalWidth('5xl')
                ->using(fn (array $data, Action $action) => VariedExpenseResource::save($data, null, $action)),
        ];
    }
}
