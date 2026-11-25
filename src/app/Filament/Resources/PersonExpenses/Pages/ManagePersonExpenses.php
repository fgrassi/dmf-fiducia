<?php

namespace App\Filament\Resources\PersonExpenses\Pages;

use App\Actions\PersonExpenses\SavePersonExpense;
use App\Filament\Resources\PersonExpenses\PersonExpenseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManagePersonExpenses extends ManageRecords
{
    protected static string $resource = PersonExpenseResource::class;

    protected static ?string $title = 'Relatório de Matriz Despesa Pessoa';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Adicionar Despesa')
                ->using(fn (array $data) => app(SavePersonExpense::class)($data, auth()->user())),
        ];
    }
}
