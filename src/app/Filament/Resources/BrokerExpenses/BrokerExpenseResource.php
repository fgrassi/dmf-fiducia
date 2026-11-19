<?php

namespace App\Filament\Resources\BrokerExpenses;

use App\Actions\Brokers\SaveBrokerExpense;
use App\Filament\Resources\BrokerExpenses\Pages\ManageBrokerExpenses;
use App\Filament\Resources\ExpenseMatrix\ExpenseMatrixResource;
use App\Models\CmnCurrency;
use App\Models\FinExpenses;
use App\Models\MatrizDespesaDespachante;
use App\Models\TipoContainer;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * despachante/addExpenses, saveExpense e removeExpense (legado): matriz de despesas de um despachante-porto. Fora do menu:
 * abre pela lista "Portos em que atua" do despachante.
 */
class BrokerExpenseResource extends Resource
{
    /** Embarque::getBillingName (legado). */
    public const BILLINGS = ['p' => 'PP', 'a' => 'PA', 'c' => 'CC', 'f' => 'FI'];

    /** FinExpenses::getOperationTypeName (legado). */
    public const TYPES = ['b' => 'Import/Export', 'i' => 'Import', 'e' => 'Export'];

    protected static ?string $model = MatrizDespesaDespachante::class;

    protected static ?string $modelLabel = 'Despesa da Matriz';

    protected static ?string $pluralModelLabel = 'Despesas da Matriz';

    protected static ?string $slug = 'cadastros/despachantes/matriz-de-despesas';

    protected static bool $shouldRegisterNavigation = false;

    /** security.yml do módulo despachante (legado): credencial auxiliares. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('auxiliares');
    }

    /** Mesma regra da matriz dos armadores: quem só lida com import ou export só lança e altera o seu tipo (mais Import/Export). */
    private static function allowedTypes(): array
    {
        return array_filter(self::TYPES, fn (string $type) => ExpenseMatrixResource::canManageType($type), ARRAY_FILTER_USE_KEY);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('ref_expense')
                    ->label('Despesa')
                    ->required()
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => FinExpenses::query()
                        ->where('name', 'like', "%{$search}%")
                        ->orderBy('name')
                        ->limit(30)
                        ->pluck('name', 'id'))
                    ->getOptionLabelUsing(fn ($value) => FinExpenses::query()->whereKey($value)->first()?->label),
                Select::make('ref_currency')
                    ->label('Moeda')
                    ->options(fn () => CmnCurrency::orderBy('name')->pluck('symbol', 'id'))
                    ->required(),
                Select::make('billing')
                    ->label('Tipo de cobrança')
                    ->options(self::BILLINGS)
                    ->required(),
                Select::make('ref_equipamento')
                    ->label('Equipamento')
                    ->options(fn () => TipoContainer::orderBy('nome')->pluck('nome', 'tipo_container_id')),
                TextInput::make('expense_value')
                    ->label('Valor Padrão')
                    ->numeric()
                    ->required(),
                Select::make('type')
                    ->label('Tipo de reserva')
                    ->options(fn () => self::allowedTypes())
                    ->required(),
                DatePicker::make('due_from')
                    ->label('Validade (a partir de)')
                    ->required(),
                DatePicker::make('due_to')
                    ->label('Validade (até)'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query, $livewire) => $query
                ->where('ref_despachante_porto', $livewire->brokerPort)
                ->when(ExpenseMatrixResource::visibleTypes(), fn ($q, array $types) => $q->whereIn('type', $types))
                ->with(['expense.unity', 'equipment', 'currency']))
            ->emptyStateHeading('Nenhum registro encontrado')
            ->filters([
                Filter::make('only_active')
                    ->label('Somente despesas ativas')
                    ->toggle()
                    ->default()
                    ->query(fn (Builder $query) => $query
                        ->where(fn ($q) => $q->whereNull('due_to')->orWhere('due_to', '>=', today()->toDateString()))),
            ])
            ->columns([
                TextColumn::make('expense.label')
                    ->label('Despesa'),
                TextColumn::make('equipment.nome')
                    ->label('Equipamento'),
                TextColumn::make('currency.symbol')
                    ->label('Moeda'),
                TextColumn::make('expense_value')
                    ->label('Valor')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2, ',', '.')),
                TextColumn::make('type')
                    ->label('Tipo de Reserva')
                    ->formatStateUsing(fn (?string $state) => self::TYPES[$state] ?? null),
                TextColumn::make('due_from')
                    ->label('Validade (de)')
                    ->date('d/m/Y'),
                TextColumn::make('due_to')
                    ->label('Validade (até)')
                    ->date('d/m/Y'),
                TextColumn::make('billing')
                    ->label('Tipo de Cobrança')
                    ->formatStateUsing(fn (?string $state) => self::BILLINGS[$state] ?? null),
            ])
            ->recordActions([
                EditAction::make()
                    ->authorize(fn (MatrizDespesaDespachante $record) => ExpenseMatrixResource::canManageType($record->type))
                    ->using(fn (MatrizDespesaDespachante $record, array $data) => app(SaveBrokerExpense::class)($record->brokerPort, $data, auth()->user(), $record)),
                DeleteAction::make()
                    ->modalDescription('Confirmar exclusão?')
                    ->authorize(fn (MatrizDespesaDespachante $record) => ExpenseMatrixResource::canManageType($record->type)),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageBrokerExpenses::route('/{brokerPort}'),
        ];
    }
}
