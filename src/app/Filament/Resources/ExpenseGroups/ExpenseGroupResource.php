<?php

namespace App\Filament\Resources\ExpenseGroups;

use App\Filament\Resources\ExpenseGroups\Pages\CreateExpenseGroup;
use App\Filament\Resources\ExpenseGroups\Pages\EditExpenseGroup;
use App\Filament\Resources\ExpenseGroups\Pages\ListExpenseGroups;
use App\Filament\Resources\ExpenseGroups\RelationManagers\ExpensesRelationManager;
use App\Models\GrupoDespesa;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * grupoDespesa (legado): grupos de despesa (nome e centro de custo) e suas despesas obrigatórias.
 * O legado não tem exclusão (o BatchDelete da listagem não existe na action) nem remoção de despesa do grupo.
 */
class ExpenseGroupResource extends Resource
{
    protected static ?string $model = GrupoDespesa::class;

    protected static ?string $modelLabel = 'Grupo de Despesa';

    protected static ?string $pluralModelLabel = 'Grupos de Despesas';

    protected static ?string $navigationLabel = 'Grupos de Despesas';

    protected static ?string $slug = 'financeiro/grupos-de-despesas';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationParentItem = 'Cadastros';

    protected static ?int $navigationSort = 39;

    /** O legado não tem security.yml neste módulo (qualquer usuário logado); aqui exige admin ou financial (divergência V6). */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential(['admin', 'financial']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('nome')
                    ->label('Nome')
                    ->required()
                    ->maxLength(255),
                Select::make('cc')
                    ->label('Tipo')
                    ->options(GrupoDespesa::COST_CENTERS)
                    ->required(),
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
                TextColumn::make('cc')
                    ->label('Tipo')
                    ->formatStateUsing(fn (?string $state) => GrupoDespesa::COST_CENTERS[$state] ?? $state),
            ])
            ->filters([
                SelectFilter::make('cc')
                    ->label('Tipo')
                    ->options(GrupoDespesa::COST_CENTERS),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ExpensesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpenseGroups::route('/'),
            'create' => CreateExpenseGroup::route('/create'),
            'edit' => EditExpenseGroup::route('/{record}/edit'),
        ];
    }
}
