<?php

namespace App\Filament\Resources\ChargeTypes;

use App\Filament\Resources\ChargeTypes\Pages\ManageChargeTypes;
use App\Models\FinChargesTypes;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** chargesTypes (legado): tipos de cobrança. */
class ChargeTypeResource extends Resource
{
    protected static ?string $model = FinChargesTypes::class;

    protected static ?string $modelLabel = 'Tipo de Cobrança';

    protected static ?string $pluralModelLabel = 'Tipos de Cobrança';

    protected static ?string $navigationLabel = 'Tipos de Cobrança';

    protected static ?string $slug = 'configuracoes/tipos-de-cobranca';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?int $navigationSort = 40;

    /** security.yml do módulo (legado): credencial admin. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('admin');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nome')
                    ->required()
                    ->maxLength(50)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->hidden(fn (FinChargesTypes $record) => $record->charges()->exists()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageChargeTypes::route('/'),
        ];
    }
}
