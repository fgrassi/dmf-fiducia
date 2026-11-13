<?php

namespace App\Filament\Resources\States;

use App\Filament\Actions\MergeRecordsAction;
use App\Filament\Clusters\Locations;
use App\Filament\Resources\States\Pages\ManageStates;
use App\Models\Estado;
use App\Models\Pais;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** estado (legado): estados/UFs de cada país. */
class StateResource extends Resource
{
    protected static ?string $model = Estado::class;

    protected static ?string $modelLabel = 'Estado';

    protected static ?string $pluralModelLabel = 'Estados';

    protected static ?string $navigationLabel = 'Estados';

    protected static ?string $slug = 'estados';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map';

    protected static ?string $cluster = Locations::class;

    protected static ?int $navigationSort = 2;

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
                TextInput::make('uf')
                    ->label('UF')
                    ->maxLength(255),
                Select::make('paises_id')
                    ->label('País')
                    ->options(fn () => Pais::orderBy('nome')->pluck('nome', 'paises_id'))
                    ->searchable()
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('nome')
            ->modifyQueryUsing(fn ($query) => $query->with('pais'))
            ->columns([
                TextColumn::make('nome')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('uf')
                    ->label('UF')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('pais.nome')
                    ->label('País'),
            ])
            ->filters([
                SelectFilter::make('paises_id')
                    ->label('País')
                    ->options(fn () => Pais::orderBy('nome')->pluck('nome', 'paises_id'))
                    ->searchable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->hidden(fn (Estado $record) => $record->isReferenced()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    MergeRecordsAction::make('state'),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageStates::route('/'),
        ];
    }
}
