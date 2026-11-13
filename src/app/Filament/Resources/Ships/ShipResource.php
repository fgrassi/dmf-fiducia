<?php

namespace App\Filament\Resources\Ships;

use App\Filament\Actions\MergeRecordsAction;
use App\Filament\Resources\Ships\Pages\ManageShips;
use App\Models\Navio;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** navio (legado): navios usados nos embarques. */
class ShipResource extends Resource
{
    protected static ?string $model = Navio::class;

    protected static ?string $modelLabel = 'Navio';

    protected static ?string $pluralModelLabel = 'Navios';

    protected static ?string $navigationLabel = 'Navios';

    protected static ?string $slug = 'cadastros/navios';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-lifebuoy';

    protected static string|UnitEnum|null $navigationGroup = 'Cadastros';

    protected static ?int $navigationSort = 100;

    /** security.yml do módulo (legado): credencial auxiliares. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('auxiliares');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('nome')
                    ->label('Nome')
                    ->required()
                    ->maxLength(255),
                TextInput::make('tamanho')
                    ->label('Tamanho')
                    ->required()
                    ->numeric()
                    ->minValue(0),
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
                TextColumn::make('tamanho')
                    ->label('Tamanho')
                    ->numeric(2, ',', '.')
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->hidden(fn (Navio $record) => $record->isReferenced()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    MergeRecordsAction::make('ship'),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageShips::route('/'),
        ];
    }
}
