<?php

namespace App\Filament\Resources\Suppliers;

use App\Actions\Expenses\MergeSuppliers;
use App\Filament\Clusters\ExpenseSettings;
use App\Filament\Resources\Suppliers\Pages\ManageSuppliers;
use App\Models\Fornecedor;
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

/** despesa/fornecedor (legado): fornecedores das despesas da empresa. Só exclui se não houver despesa nem vencimento. */
class SupplierResource extends Resource
{
    protected static ?string $model = Fornecedor::class;

    protected static ?string $modelLabel = 'Fornecedor';

    protected static ?string $pluralModelLabel = 'Fornecedores';

    protected static ?string $navigationLabel = 'Fornecedores';

    protected static ?string $slug = 'fornecedores';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static ?string $cluster = ExpenseSettings::class;

    protected static ?int $navigationSort = 1;

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
                TextInput::make('telefone')
                    ->label('Telefone')
                    ->maxLength(20),
                TextInput::make('cnpj')
                    ->label('CNPJ')
                    ->maxLength(20),
                TextInput::make('email')
                    ->label('E-mail')
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
                TextColumn::make('telefone')
                    ->label('Telefone'),
                TextColumn::make('cnpj')
                    ->label('CNPJ')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('E-mail'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->hidden(fn (Fornecedor $record) => $record->isReferenced()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('unir')
                        ->label('Unir selecionados')
                        ->icon(Heroicon::OutlinedArrowsPointingIn)
                        ->modalHeading('Unir fornecedores')
                        ->modalDescription('Escolha o fornecedor que permanece. Os demais selecionados são removidos e suas despesas e vencimentos passam para ele.')
                        ->schema(fn (Collection $records) => [
                            Select::make('destino')
                                ->label('Fornecedor destino')
                                ->options($records->pluck('nome', 'fornecedor_id'))
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data, MergeSuppliers $merge) {
                            try {
                                $merge($records->modelKeys(), (int) $data['destino']);
                                Notification::make()->title('Fornecedores unidos')->success()->send();
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
            'index' => ManageSuppliers::route('/'),
        ];
    }
}
