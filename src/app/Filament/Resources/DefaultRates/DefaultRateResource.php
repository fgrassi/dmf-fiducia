<?php

namespace App\Filament\Resources\DefaultRates;

use App\Filament\Resources\DefaultRates\Pages\ManageDefaultRates;
use App\Models\FinCurrenciesRates;
use App\Models\FinDefaultRates;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** taxaPadrao (legado): taxa (%) padrão aplicada por cotação no recálculo da cobrança. */
class DefaultRateResource extends Resource
{
    protected static ?string $model = FinDefaultRates::class;

    protected static ?string $modelLabel = 'Taxa Padrão';

    protected static ?string $pluralModelLabel = 'Taxas Padrão';

    protected static ?string $navigationLabel = 'Taxas Padrão';

    protected static ?string $slug = 'financeiro/taxas-padrao';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationParentItem = 'Cadastros';

    protected static ?int $navigationSort = 90;

    /** security.yml do módulo (legado): credencial admin ou financial. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential(['admin', 'financial']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('ref_currency_rate')
                    ->label('Cotação')
                    ->options(fn () => FinCurrenciesRates::with('currency')->orderBy('name')->get()->pluck('label', 'id'))
                    ->required(),
                TextInput::make('default_tax')
                    ->label('Taxa Padrão (%)')
                    ->numeric()
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->modifyQueryUsing(fn ($query) => $query->with('rate.currency'))
            ->columns([
                TextColumn::make('rate.label')
                    ->label('Cotação'),
                TextColumn::make('default_tax')
                    ->label('Taxa Padrão')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 3, ',', ' ').'%'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageDefaultRates::route('/'),
        ];
    }
}
