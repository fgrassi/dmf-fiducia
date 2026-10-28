<?php

namespace App\Filament\Resources\CarrierPaymentRates;

use App\Actions\CurrencyRates\SaveRateValue;
use App\Filament\Actions\RateValueAction;
use App\Filament\Resources\CarrierPaymentRates\Pages\ManageCarrierPaymentRates;
use App\Filament\Resources\CurrencyRates\CurrencyRateResource;
use App\Models\Armador;
use App\Models\CmnCurrency;
use App\Models\FinCurrenciesRates;
use App\Models\FinCurrenciesRatesArmadorPayment;
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

/**
 * currencyRateArmadorPayment e currencyRateArmadorPaymentValues (legado): cotação de pagamento ao armador (base da
 * ordem de pagamento), opcionalmente ligada a uma cotação com fator %, e o lançamento diário do valor.
 */
class CarrierPaymentRateResource extends Resource
{
    protected static ?string $model = FinCurrenciesRatesArmadorPayment::class;

    protected static ?string $modelLabel = 'Cotação de Pagamento de Armador';

    protected static ?string $pluralModelLabel = 'Cotações de Pagamento de Armador';

    protected static ?string $navigationLabel = 'Cotações de Pagamento';

    protected static ?string $slug = 'financeiro/cotacoes-pagamento-armador';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationParentItem = 'Cadastros';

    protected static ?int $navigationSort = 36;

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
                Select::make('type')
                    ->label('Tipo')
                    ->options(FinCurrenciesRatesArmadorPayment::TYPES),
                Select::make('fin_currencies_rates_id')
                    ->label('Cotação')
                    ->options(fn () => FinCurrenciesRates::orderBy('name')->pluck('name', 'id')),
                TextInput::make('fator')
                    ->label('Fator (%)')
                    ->numeric(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query
                ->withLatestValue()
                ->with(['armador', 'currency', 'rate'])
                ->join('armador', 'armador.armador_id', '=', 'fin_currencies_rates_armador_payment.ref_armador')
                ->orderBy('armador.nome'))
            ->columns([
                TextColumn::make('armador.nome')
                    ->label('Armador')
                    ->searchable(),
                TextColumn::make('currency.name')
                    ->label('Moeda'),
                TextColumn::make('type')
                    ->label('Tipo')
                    ->formatStateUsing(fn (?string $state) => FinCurrenciesRatesArmadorPayment::TYPES[$state] ?? null),
                TextColumn::make('rate.name')
                    ->label('Cotação'),
                TextColumn::make('fator')
                    ->label('Fator (%)'),
                TextColumn::make('latest_rate')
                    ->label('Valor')
                    ->formatStateUsing(fn ($state) => $state === null ? null : number_format((float) $state, 4, ',', '.')),
                TextColumn::make('latest_date')
                    ->label('Data')
                    ->date('d/m/Y')
                    ->badge()
                    ->color(fn (?string $state) => CurrencyRateResource::freshness($state)),
            ])
            ->filters([
                SelectFilter::make('ref_armador')
                    ->label('Armador')
                    ->options(fn () => Armador::orderBy('nome')->pluck('nome', 'armador_id'))
                    ->searchable(),
            ])
            ->recordActions([
                RateValueAction::forRecord(SaveRateValue::CARRIER_PAYMENT),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCarrierPaymentRates::route('/'),
        ];
    }
}
