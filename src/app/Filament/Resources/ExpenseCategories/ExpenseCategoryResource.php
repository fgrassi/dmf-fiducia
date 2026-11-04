<?php

namespace App\Filament\Resources\ExpenseCategories;

use App\Actions\Expenses\MergeExpenseCategories;
use App\Filament\Clusters\ExpenseSettings;
use App\Filament\Resources\ExpenseCategories\Pages\ManageExpenseCategories;
use App\Models\CategoriaDespesa;
use BackedEnum;
use DomainException;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

/** despesa/categoriaDespesa (legado): categorias das despesas da empresa, com "Unir selecionadas". */
class ExpenseCategoryResource extends Resource
{
    protected static ?string $model = CategoriaDespesa::class;

    protected static ?string $modelLabel = 'Categoria';

    protected static ?string $pluralModelLabel = 'Categorias';

    protected static ?string $navigationLabel = 'Categorias';

    protected static ?string $slug = 'categorias';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static ?string $cluster = ExpenseSettings::class;

    protected static ?int $navigationSort = 3;

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
                    ->hidden(fn (CategoriaDespesa $record) => $record->isReferenced()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('unir')
                        ->label('Unir selecionadas')
                        ->icon(Heroicon::OutlinedArrowsPointingIn)
                        ->modalHeading('Unir categorias')
                        ->modalDescription('Escolha a categoria que permanece. As demais selecionadas são removidas e seus registros migrados.')
                        ->schema(fn (Collection $records) => [
                            Select::make('destino')
                                ->label('Categoria destino')
                                ->options($records->pluck('nome', 'categoria_despesa_id'))
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data, MergeExpenseCategories $merge) {
                            try {
                                $merge($records->modelKeys(), (int) $data['destino']);
                                Notification::make()->title('Categorias unidas')->success()->send();
                            } catch (DomainException $e) {
                                Notification::make()->title($e->getMessage())->danger()->send();
                            }
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageExpenseCategories::route('/'),
        ];
    }
}
