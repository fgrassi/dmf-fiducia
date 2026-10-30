<?php

namespace App\Filament\Resources\ManualAccountingCovers;

use App\Filament\Resources\ManualAccountingCovers\Pages\ListManualAccountingCovers;
use App\Filament\Resources\ManualAccountingCovers\Tables\ManualAccountingCoversTable;
use App\Models\CapaProcessoManual;
use App\Models\CmnCurrency;
use App\Models\FinExpenses;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/** Módulo capasContabilidadeManual do legado, na aba "Capas manuais" da lista de capas. */
class ManualAccountingCoverResource extends Resource
{
    protected static ?string $model = CapaProcessoManual::class;

    protected static ?string $modelLabel = 'Capa Manual';

    protected static ?string $pluralModelLabel = 'Capas Manuais';

    protected static ?string $slug = 'financeiro/capas-de-contabilidade-manuais';

    /** Acessada pela aba da lista de capas, não pelo menu. */
    protected static bool $shouldRegisterNavigation = false;

    /** security.yml do módulo (legado): credencial financial ou director. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential(['financial', 'director']);
    }

    /** CapaProcessoManualForm (legado): cliente, moeda e cotação, lucro em USD/EUR e o item (despesa, compra e venda). */
    public static function form(Schema $schema): Schema
    {
        $currencies = fn () => CmnCurrency::orderBy('name')->pluck('name', 'id')->all();

        return $schema
            ->components([
                Select::make('cliente_id')
                    ->label('Cliente')
                    ->searchable()
                    ->required()
                    ->getSearchResultsUsing(fn (string $search) => DB::table('pessoa')->where('nome', 'like', "%{$search}%")->orderBy('nome')->limit(30)->pluck('nome', 'pessoa_id')->all())
                    ->getOptionLabelUsing(fn ($value) => DB::table('pessoa')->where('pessoa_id', $value)->value('nome')),
                TextInput::make('nome_cliente')
                    ->label('Nome Cliente'),
                Select::make('moeda_id')
                    ->label('Moeda')
                    ->options($currencies)
                    ->required(),
                TextInput::make('valor_cotacao')
                    ->label('Valor Cotação')
                    ->numeric()
                    ->default(0),
                DatePicker::make('payment_date')
                    ->label('Data de Pagamento')
                    ->default(now()),
                Select::make('moeda_usd_eur')
                    ->label('Moeda do Lucro USD/EUR')
                    ->options($currencies),
                TextInput::make('valor_usd_eur')
                    ->label('Lucro USD/EUR')
                    ->numeric()
                    ->default(0),
                Select::make('ref_expense')
                    ->label('Despesa')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => FinExpenses::with('unity')->where('name', 'like', "{$search}%")->orderBy('name')->limit(30)->get()->pluck('label', 'id')->all())
                    ->getOptionLabelUsing(fn ($value) => FinExpenses::find($value)?->label),
                Select::make('ref_currency_br')
                    ->label('Moeda Compra')
                    ->options($currencies)
                    ->required(),
                TextInput::make('buying_rate')
                    ->label('Valor Compra')
                    ->numeric()
                    ->default(0),
                Select::make('ref_currency_sr')
                    ->label('Moeda Venda')
                    ->options($currencies)
                    ->required(),
                TextInput::make('selling_rate')
                    ->label('Valor Venda')
                    ->numeric()
                    ->default(0),
            ]);
    }

    public static function table(Table $table): Table
    {
        return ManualAccountingCoversTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListManualAccountingCovers::route('/'),
        ];
    }
}
