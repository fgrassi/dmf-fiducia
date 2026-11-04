<?php

namespace App\Filament\Resources\Cargos;

use App\Filament\Resources\Cargos\Pages\ManageCargos;
use App\Models\Cargo;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** cargo (legado): cargos dos usuários nas empresas. */
class CargoResource extends Resource
{
    protected static ?string $model = Cargo::class;

    protected static ?string $modelLabel = 'Cargo';

    protected static ?string $pluralModelLabel = 'Cargos';

    protected static ?string $navigationLabel = 'Cargos';

    protected static ?string $slug = 'configuracoes/cargos';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-identification';

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?string $navigationParentItem = 'Configurações Usuários';

    protected static ?int $navigationSort = 20;

    /** security.yml do módulo (legado): credencial usuario. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('usuario');
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
                    ->hidden(fn (Cargo $record) => $record->isReferenced()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCargos::route('/'),
        ];
    }
}
