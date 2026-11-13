<?php

namespace App\Filament\Resources\Cities;

use App\Filament\Actions\MergeRecordsAction;
use App\Filament\Clusters\Locations;
use App\Filament\Resources\Cities\Pages\ManageCities;
use App\Models\Cidade;
use App\Models\Estado;
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

/** cidade (legado): cidades de cada estado. */
class CityResource extends Resource
{
    protected static ?string $model = Cidade::class;

    protected static ?string $modelLabel = 'Cidade';

    protected static ?string $pluralModelLabel = 'Cidades';

    protected static ?string $navigationLabel = 'Cidades';

    protected static ?string $slug = 'cidades';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $cluster = Locations::class;

    protected static ?int $navigationSort = 3;

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
                Select::make('estados_id')
                    ->label('Estado')
                    ->options(fn () => self::stateOptions())
                    ->searchable()
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('nome')
            ->modifyQueryUsing(fn ($query) => $query->with('estado'))
            ->columns([
                TextColumn::make('nome')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('estado.nome')
                    ->label('Estado'),
                TextColumn::make('estado.uf')
                    ->label('UF'),
            ])
            ->filters([
                SelectFilter::make('estados_id')
                    ->label('Estado')
                    ->options(fn () => self::stateOptions())
                    ->searchable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->hidden(fn (Cidade $record) => $record->isReferenced()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    MergeRecordsAction::make('city'),
                ]),
            ]);
    }

    /** @return array<int, string> estados_id => "Nome (UF)" */
    private static function stateOptions(): array
    {
        return Estado::orderBy('nome')->get()->mapWithKeys(fn (Estado $state) => [$state->estados_id => $state->uf ? "{$state->nome} ({$state->uf})" : $state->nome])->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCities::route('/'),
        ];
    }
}
