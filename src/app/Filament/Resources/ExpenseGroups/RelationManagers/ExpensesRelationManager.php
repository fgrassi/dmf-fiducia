<?php

namespace App\Filament\Resources\ExpenseGroups\RelationManagers;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\FinExpenses;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Despesas obrigatórias do grupo (showSuccess / addRegra do legado): só inclui, sem remover. */
class ExpensesRelationManager extends RelationManager
{
    protected static string $relationship = 'expenses';

    protected static ?string $title = 'Despesas obrigatórias';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('despesa_id')
                    ->label('Despesa')
                    ->required()
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => FinExpenses::query()
                        ->where('name', 'like', "%{$search}%")
                        ->orderBy('name')
                        ->limit(30)
                        ->pluck('name', 'id'))
                    ->getOptionLabelUsing(fn ($value) => FinExpenses::query()->whereKey($value)->value('name')),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('expense.unity'))
            ->columns([
                TextColumn::make('expense.label')
                    ->label('Despesa'),
                TextColumn::make('expense.operation_type')
                    ->label('Tipo')
                    ->formatStateUsing(fn (?string $state) => ExpenseResource::OPERATION_TYPES[$state] ?? null),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Adicionar despesa'),
            ]);
    }
}
