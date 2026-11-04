<?php

namespace App\Filament\Resources\CostCenters;

use App\Filament\Clusters\ExpenseSettings;
use App\Filament\Resources\CostCenters\Pages\ManageCostCenters;
use App\Models\CentroCusto;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** despesa/centroCusto (legado): centros de custo das despesas da empresa. */
class CostCenterResource extends Resource
{
    protected static ?string $model = CentroCusto::class;

    protected static ?string $modelLabel = 'Centro de Custo';

    protected static ?string $pluralModelLabel = 'Centros de Custo';

    protected static ?string $navigationLabel = 'Centros de Custo';

    protected static ?string $slug = 'centros-de-custo';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office';

    protected static ?string $cluster = ExpenseSettings::class;

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->canManageCompanyExpenses();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('nome')
                    ->label('Nome')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('nome')
            ->columns([
                TextColumn::make('nome')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->hidden(fn (CentroCusto $record) => $record->isReferenced()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCostCenters::route('/'),
        ];
    }
}
