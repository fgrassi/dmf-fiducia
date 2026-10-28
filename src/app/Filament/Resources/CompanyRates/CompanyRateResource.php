<?php

namespace App\Filament\Resources\CompanyRates;

use App\Filament\Resources\CompanyRates\Pages\ManageCompanyRates;
use App\Models\FinCompaniesRates;
use App\Models\FinCurrenciesRates;
use App\Models\Pessoa;
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

/** taxasEmpresas (legado): taxa (%) negociada por cliente e cotação, que substitui a taxa padrão no recálculo da cobrança. */
class CompanyRateResource extends Resource
{
    protected static ?string $model = FinCompaniesRates::class;

    protected static ?string $modelLabel = 'Taxa Negociada';

    protected static ?string $pluralModelLabel = 'Taxas Negociadas';

    protected static ?string $navigationLabel = 'Taxas Negociadas';

    protected static ?string $slug = 'financeiro/taxas-negociadas';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationParentItem = 'Cadastros';

    protected static ?int $navigationSort = 38;

    /** security.yml do módulo (legado): credencial financial. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('financial');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('ref_pessoa')
                    ->label('Cliente')
                    ->required()
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Pessoa::query()
                        ->where('nome', 'like', "%{$search}%")
                        ->orderBy('nome')
                        ->limit(30)
                        ->pluck('nome', 'pessoa_id'))
                    ->getOptionLabelUsing(fn ($value) => Pessoa::query()->whereKey($value)->value('nome')),
                Select::make('ref_currency_rate')
                    ->label('Cotação')
                    ->options(fn () => FinCurrenciesRates::with('currency')->orderBy('name')->get()->pluck('label', 'id'))
                    ->required(),
                TextInput::make('tax')
                    ->label('Taxa (%)')
                    ->numeric()
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query
                ->select('fin_companies_rates.*')
                ->join('pessoa', 'pessoa.pessoa_id', '=', 'fin_companies_rates.ref_pessoa')
                ->with('rate.currency')
                ->orderBy('pessoa.nome'))
            ->columns([
                TextColumn::make('pessoa.nome')
                    ->label('Cliente')
                    ->searchable(query: fn ($query, string $search) => $query->where('pessoa.nome', 'like', "%{$search}%")),
                TextColumn::make('rate.label')
                    ->label('Cotação'),
                TextColumn::make('tax')
                    ->label('Taxa')
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
            'index' => ManageCompanyRates::route('/'),
        ];
    }
}
