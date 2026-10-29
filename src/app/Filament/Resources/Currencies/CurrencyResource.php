<?php

namespace App\Filament\Resources\Currencies;

use App\Filament\Resources\Currencies\Pages\ManageCurrencies;
use App\Models\CmnCurrency;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** currency (legado): cadastro de moedas. */
class CurrencyResource extends Resource
{
    protected static ?string $model = CmnCurrency::class;

    protected static ?string $modelLabel = 'Moeda';

    protected static ?string $pluralModelLabel = 'Moedas';

    protected static ?string $navigationLabel = 'Moedas';

    protected static ?string $slug = 'financeiro/moedas';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-currency-dollar';

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationParentItem = 'Cadastros';

    protected static ?int $navigationSort = 70;

    /** security.yml do módulo (legado): credencial financial. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('financial');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nome')
                    ->required()
                    ->maxLength(50),
                TextInput::make('symbol')
                    ->label('Símbolo')
                    ->required()
                    ->maxLength(10),
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
                TextColumn::make('symbol')
                    ->label('Símbolo'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->hidden(fn (CmnCurrency $record) => $record->isReferenced()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCurrencies::route('/'),
        ];
    }
}
