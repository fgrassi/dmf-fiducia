<?php

namespace App\Filament\Resources\ExpenseMatrix;

use App\Actions\Carriers\UpdateMatrixExpense;
use App\Filament\Resources\ExpenseMatrix\Pages\ManageExpenseMatrix;
use App\Models\CmnCurrency;
use App\Models\FinExpenses;
use App\Models\FinExpensesMatrix;
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
use Illuminate\Support\Facades\DB;

/**
 * listExpenses, editExpenses e as edições em linha (editDueTo, editValorExpense, etc.) do armador (legado): matriz de
 * despesas de um armador-porto. Fora do menu: abre pela aba "Portos em que atua" do armador.
 */
class ExpenseMatrixResource extends Resource
{
    /** Embarque::getBillingName (legado). */
    public const BILLINGS = [
        'p' => 'PP',
        'c' => 'CC',
        'a' => 'PA',
    ];

    public const TYPES = [
        'i' => 'Import',
        'e' => 'Export',
    ];

    protected static ?string $model = FinExpensesMatrix::class;

    protected static ?string $modelLabel = 'Despesa da Matriz';

    protected static ?string $pluralModelLabel = 'Despesas da Matriz';

    protected static ?string $slug = 'cadastros/armadores/matriz-de-despesas';

    protected static bool $shouldRegisterNavigation = false;

    /** security.yml do módulo armador (legado): credencial auxiliares. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('auxiliares');
    }

    /**
     * Tipos de reserva que o usuário enxerga (legado): import e export, ou nenhum dos dois, vê tudo; só um deles vê o seu
     * (mais as despesas Import/Export `b`).
     *
     * @return list<string>|null null: todos
     */
    public static function visibleTypes(): ?array
    {
        $user = auth()->user();
        $import = $user->hasLegacyCredential('import');
        $export = $user->hasLegacyCredential('export');

        return match (true) {
            $import === $export => null,
            $export => ['e', 'b'],
            default => ['i', 'b'],
        };
    }

    /** O usuário só enxerga e altera despesas dos tipos de reserva da sua credencial (V-C5). */
    public static function canManageType(?string $type): bool
    {
        $visible = self::visibleTypes();

        return $visible === null || in_array($type, $visible, true);
    }

    /** Tipos que o usuário pode lançar (editExpenses do legado). */
    public static function allowedTypes(): array
    {
        $visible = self::visibleTypes();

        return $visible === null ? self::TYPES : array_intersect_key(self::TYPES, array_flip($visible));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('type')
                    ->label('Tipo de reserva')
                    ->options(fn () => self::allowedTypes())
                    ->required(),
                Select::make('ref_expense')
                    ->label('Despesa')
                    ->required()
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => FinExpenses::query()
                        ->where('name', 'like', "%{$search}%")
                        ->orderBy('name')
                        ->limit(30)
                        ->pluck('name', 'id'))
                    ->getOptionLabelUsing(fn ($value) => FinExpenses::query()->whereKey($value)->value('name'))
                    ->visibleOn('create'),
                Select::make('ref_equipamento')
                    ->label('Equipamento')
                    ->options(fn () => TipoContainer::orderBy('nome')->pluck('nome', 'tipo_container_id'))
                    ->multiple(fn (string $operation) => $operation === 'create')
                    ->helperText(fn (string $operation) => $operation === 'create' ? 'Uma linha por equipamento; sem escolher, vale para todos.' : null),
                Select::make('ref_currency')
                    ->label('Moeda')
                    ->options(fn () => CmnCurrency::orderBy('name')->pluck('symbol', 'id'))
                    ->required(),
                Select::make('billing')
                    ->label('Tipo de cobrança')
                    ->options(self::BILLINGS)
                    ->required(),
                TextInput::make('expense_value')
                    ->label('Valor Padrão')
                    ->numeric()
                    ->required(),
                TextInput::make('imo_value')
                    ->label('Valor IMO')
                    ->numeric(),
                TextInput::make('compra_minimo')
                    ->label('Custo Mínimo')
                    ->numeric()
                    ->disabled(fn (?FinExpensesMatrix $record) => $record && $record->expense?->ref_unity != 3),
                TextInput::make('venda_minimo')
                    ->label('Venda Mínima')
                    ->numeric()
                    ->disabled(fn (?FinExpensesMatrix $record) => $record && $record->expense?->ref_unity != 3),
                Select::make('pais_origem_id')
                    ->label('País de Origem')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => DB::table('paises')
                        ->where('nome', 'like', "%{$search}%")
                        ->orderBy('nome')
                        ->limit(30)
                        ->pluck('nome', 'paises_id'))
                    ->getOptionLabelUsing(fn ($value) => DB::table('paises')->where('paises_id', $value)->value('nome'))
                    ->visibleOn('create'),
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
                ->where('ref_armador_porto', $livewire->armadorPorto)
                ->when(self::visibleTypes(), fn ($q, array $types) => $q->whereIn('type', $types))
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
                TextColumn::make('imo_value')
                    ->label('Valor IMO')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2, ',', '.')),
                TextColumn::make('compra_minimo')
                    ->label('Custo Min')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2, ',', '.')),
                TextColumn::make('venda_minimo')
                    ->label('Venda Min')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2, ',', '.')),
                TextColumn::make('type')
                    ->label('Tipo de Reserva')
                    ->formatStateUsing(fn (?string $state) => ['b' => 'Import/Export', ...self::TYPES][$state] ?? null),
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
                    ->authorize(fn (FinExpensesMatrix $record) => self::canManageType($record->type))
                    ->using(fn (FinExpensesMatrix $record, array $data) => app(UpdateMatrixExpense::class)($record, $data, auth()->user())),
                DeleteAction::make()
                    ->authorize(fn (FinExpensesMatrix $record) => self::canManageType($record->type)),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageExpenseMatrix::route('/{armadorPorto}'),
        ];
    }
}
