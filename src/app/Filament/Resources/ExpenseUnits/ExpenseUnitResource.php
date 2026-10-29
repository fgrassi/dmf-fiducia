<?php

namespace App\Filament\Resources\ExpenseUnits;

use App\Filament\Resources\ExpenseUnits\Pages\ManageExpenseUnits;
use App\Models\FinExpensesUnity;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** expensesUnity (legado): unidades das despesas (nome e sigla). */
class ExpenseUnitResource extends Resource
{
    protected static ?string $model = FinExpensesUnity::class;

    protected static ?string $modelLabel = 'Unidade de Despesa';

    protected static ?string $pluralModelLabel = 'Unidades de Despesa';

    protected static ?string $navigationLabel = 'Unidades de Despesa';

    protected static ?string $slug = 'financeiro/unidades-de-despesa';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?int $navigationSort = 50;

    /** security.yml do módulo (legado): credencial admin. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('admin');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nome')
                    ->required()
                    ->maxLength(50),
                TextInput::make('acronym')
                    ->label('Sigla')
                    ->maxLength(10),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('acronym')
                    ->label('Sigla'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->hidden(fn (FinExpensesUnity $record) => $record->isReferenced()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageExpenseUnits::route('/'),
        ];
    }
}
