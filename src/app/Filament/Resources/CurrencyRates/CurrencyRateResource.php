<?php

namespace App\Filament\Resources\CurrencyRates;

use App\Actions\CurrencyRates\SaveRateValue;
use App\Filament\Actions\RateValueAction;
use App\Filament\Resources\CurrencyRates\Pages\ManageCurrencyRates;
use App\Models\CmnCurrency;
use App\Models\FinCurrenciesRates;
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

/**
 * currencyRate e currencyRateValues (legado): cotações (com vínculo a uma cotação base e fator %) e o lançamento
 * diário do valor. Lançar a base atualiza as cotações vinculadas.
 */
class CurrencyRateResource extends Resource
{
    protected static ?string $model = FinCurrenciesRates::class;

    protected static ?string $modelLabel = 'Cotação';

    protected static ?string $pluralModelLabel = 'Cotações';

    protected static ?string $navigationLabel = 'Cotações';

    protected static ?string $slug = 'financeiro/cotacoes';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationParentItem = 'Cadastros';

    protected static ?int $navigationSort = 20;

    /** security.yml dos módulos (legado): credencial financial. */
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
                Select::make('ref_currency')
                    ->label('Moeda')
                    ->options(fn () => CmnCurrency::orderBy('name')->pluck('name', 'id'))
                    ->required(),
                Select::make('ref_currency_rate')
                    ->label('Cotação Base')
                    ->options(fn (?FinCurrenciesRates $record) => FinCurrenciesRates::query()
                        ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
                        ->orderBy('name')
                        ->pluck('name', 'id')),
                TextInput::make('fator')
                    ->label('Fator (%)')
                    ->numeric(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->modifyQueryUsing(fn ($query) => $query->withLatestValue()->with(['currency', 'base']))
            ->columns([
                TextColumn::make('name')
                    ->label('Cotação')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('currency.name')
                    ->label('Moeda'),
                TextColumn::make('base.name')
                    ->label('Vínculo'),
                TextColumn::make('fator')
                    ->label('Fator (%)'),
                TextColumn::make('latest_rate')
                    ->label('Valor')
                    ->formatStateUsing(fn ($state) => $state === null ? null : number_format((float) $state, 4, ',', '.')),
                TextColumn::make('latest_date')
                    ->label('Data')
                    ->date('d/m/Y')
                    ->badge()
                    ->color(fn (?string $state) => self::freshness($state)),
            ])
            ->recordActions([
                RateValueAction::forRecord(SaveRateValue::CURRENCY),
                EditAction::make(),
                DeleteAction::make()
                    ->hidden(fn (FinCurrenciesRates $record) => $record->isReferenced()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCurrencyRates::route('/'),
        ];
    }

    /** Semáforo do legado: sem valor cinza; hoje verde; ontem amarelo; mais antigo vermelho. */
    public static function freshness(?string $date): string
    {
        return match (true) {
            $date === null => 'gray',
            $date >= now()->toDateString() => 'success',
            $date === now()->subDay()->toDateString() => 'warning',
            default => 'danger',
        };
    }
}
