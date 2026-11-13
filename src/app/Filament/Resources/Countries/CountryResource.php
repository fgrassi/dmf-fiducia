<?php

namespace App\Filament\Resources\Countries;

use App\Filament\Actions\MergeRecordsAction;
use App\Filament\Clusters\Locations;
use App\Filament\Resources\Countries\Pages\ManageCountries;
use App\Models\Pais;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** pais (legado): países. */
class CountryResource extends Resource
{
    protected static ?string $model = Pais::class;

    protected static ?string $modelLabel = 'País';

    protected static ?string $pluralModelLabel = 'Países';

    protected static ?string $navigationLabel = 'Países';

    protected static ?string $slug = 'paises';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-globe-americas';

    protected static ?string $cluster = Locations::class;

    protected static ?int $navigationSort = 1;

    /** security.yml do módulo (legado): credencial localidade. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('localidade');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('nome')
                    ->label('Nome')
                    ->required()
                    ->maxLength(255),
                TextInput::make('uncode')
                    ->label('UN Code')
                    ->maxLength(255),
                TextInput::make('siscoserv_id')
                    ->label('Siscoserv')
                    ->maxLength(255),
                Textarea::make('observacao')
                    ->label('Observação'),
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
                TextColumn::make('uncode')
                    ->label('UN Code')
                    ->searchable()
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->hidden(fn (Pais $record) => $record->isReferenced()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    MergeRecordsAction::make('country'),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCountries::route('/'),
        ];
    }
}
