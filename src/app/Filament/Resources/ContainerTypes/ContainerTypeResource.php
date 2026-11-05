<?php

namespace App\Filament\Resources\ContainerTypes;

use App\Filament\Resources\ContainerTypes\Pages\ManageContainerTypes;
use App\Models\TipoContainer;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** tipoContainer (legado): equipamentos usados em mercadorias, containers, matriz de despesas e cotações. */
class ContainerTypeResource extends Resource
{
    protected static ?string $model = TipoContainer::class;

    protected static ?string $modelLabel = 'Tipo de Container';

    protected static ?string $pluralModelLabel = 'Tipos de Container';

    protected static ?string $navigationLabel = 'Tipos de Container';

    protected static ?string $slug = 'cadastros/tipos-de-container';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    protected static string|UnitEnum|null $navigationGroup = 'Cadastros';

    protected static ?int $navigationSort = 110;

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
                Select::make('tipo')
                    ->label('Tipo')
                    ->required()
                    ->options(array_combine(TipoContainer::TIPOS, TipoContainer::TIPOS))
                    ->default('outro'),
                Select::make('tipo_embarque')
                    ->label('Tipo de Embarque')
                    ->required()
                    ->options(TipoContainer::TIPOS_EMBARQUE),
                TextInput::make('iso_code')
                    ->label('Código ISO')
                    ->maxLength(4),
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
                TextColumn::make('tipo')
                    ->label('Tipo')
                    ->sortable(),
                TextColumn::make('tipo_embarque')
                    ->label('Tipo de Embarque')
                    ->formatStateUsing(fn (?string $state) => TipoContainer::TIPOS_EMBARQUE[$state] ?? $state),
                TextColumn::make('iso_code')
                    ->label('Código ISO'),
            ])
            ->filters([
                SelectFilter::make('tipo')
                    ->label('Tipo')
                    ->options(array_combine(TipoContainer::TIPOS, TipoContainer::TIPOS)),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageContainerTypes::route('/'),
        ];
    }
}
