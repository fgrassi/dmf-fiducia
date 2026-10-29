<?php

namespace App\Filament\Resources\CarrierRates;

use App\Actions\CurrencyRates\SaveRateValue;
use App\Filament\Actions\RateValueAction;
use App\Filament\Resources\CarrierRates\Pages\ManageCarrierRates;
use App\Filament\Resources\CurrencyRates\CurrencyRateResource;
use App\Models\Armador;
use App\Models\CmnCurrency;
use App\Models\FinCurrenciesRatesArmador;
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

/** currencyRateArmador e currencyRateArmadorValues (legado): cotação por armador e moeda e o lançamento diário do valor. */
class CarrierRateResource extends Resource
{
    protected static ?string $model = FinCurrenciesRatesArmador::class;

    protected static ?string $modelLabel = 'Cotação de Armador';

    protected static ?string $pluralModelLabel = 'Cotações de Armador';

    protected static ?string $navigationLabel = 'Cotações de Armador';

    protected static ?string $slug = 'financeiro/cotacoes-armador';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationParentItem = 'Cadastros';

    protected static ?int $navigationSort = 30;

    /** security.yml dos módulos (legado): credencial financial. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('financial');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('ref_armador')
                    ->label('Armador')
                    ->options(fn () => Armador::orderBy('nome')->pluck('nome', 'armador_id'))
                    ->searchable()
                    ->required(),
                Select::make('ref_currency')
                    ->label('Moeda')
                    ->options(fn () => CmnCurrency::orderBy('name')->pluck('name', 'id'))
                    ->required(),
                TextInput::make('name')
                    ->label('Nome')
                    ->maxLength(50),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query
                ->withLatestValue()
                ->with(['armador', 'currency'])
                ->join('armador', 'armador.armador_id', '=', 'fin_currencies_rates_armador.ref_armador')
                ->orderBy('armador.nome'))
            ->columns([
                TextColumn::make('armador.nome')
                    ->label('Armador')
                    ->searchable(),
                TextColumn::make('currency.name')
                    ->label('Moeda'),
                TextColumn::make('name')
                    ->label('Nome'),
                TextColumn::make('latest_rate')
                    ->label('Valor')
                    ->formatStateUsing(fn ($state) => $state === null ? null : number_format((float) $state, 4, ',', '.')),
                TextColumn::make('latest_date')
                    ->label('Data')
                    ->date('d/m/Y')
                    ->badge()
                    ->color(fn (?string $state) => CurrencyRateResource::freshness($state)),
            ])
            ->recordActions([
                RateValueAction::forRecord(SaveRateValue::CARRIER),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCarrierRates::route('/'),
        ];
    }
}
