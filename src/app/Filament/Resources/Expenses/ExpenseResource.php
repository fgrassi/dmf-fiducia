<?php

namespace App\Filament\Resources\Expenses;

use App\Filament\Resources\Expenses\Pages\ManageExpenses;
use App\Models\CmnCurrency;
use App\Models\FinExpenses;
use App\Models\FinExpensesUnity;
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

/** expenses (legado): cadastro de despesas (tipo de operação, unidade, moeda e valores mínimos LCL). */
class ExpenseResource extends Resource
{
    /** FinExpensesTable::$operation_type (legado). */
    public const OPERATION_TYPES = [
        'b' => 'Import/Export',
        'i' => 'Import',
        'e' => 'Export',
    ];

    protected static ?string $model = FinExpenses::class;

    protected static ?string $modelLabel = 'Despesa';

    protected static ?string $pluralModelLabel = 'Despesas';

    protected static ?string $navigationLabel = 'Despesas';

    protected static ?string $slug = 'financeiro/despesas';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationParentItem = 'Cadastros';

    protected static ?int $navigationSort = 33;

    /** security.yml do módulo (legado): credencial booking (é ela, não financial, que o legado exige aqui). */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('booking');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Despesa')
                    ->required()
                    ->maxLength(45),
                Select::make('operation_type')
                    ->label('Tipo de Operação')
                    ->options(self::OPERATION_TYPES),
                Select::make('ref_unity')
                    ->label('Unidade')
                    ->options(fn () => FinExpensesUnity::orderBy('name')->pluck('name', 'id')),
                Select::make('ref_currency')
                    ->label('Moeda (LCL)')
                    ->options(fn () => CmnCurrency::orderBy('name')->pluck('name', 'id')),
                TextInput::make('min_value')
                    ->label('Valor Mínimo (LCL)')
                    ->numeric()
                    ->minValue(0),
                TextInput::make('min_value_br')
                    ->label('Valor Mínimo Compra (LCL)')
                    ->numeric()
                    ->minValue(0),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->modifyQueryUsing(fn ($query) => $query->with(['unity', 'currency']))
            ->columns([
                TextColumn::make('label')
                    ->label('Despesa')
                    ->searchable(['name'])
                    ->sortable(['name']),
                TextColumn::make('min_value_br')
                    ->label('Valor Mínimo B/R')
                    ->formatStateUsing(fn ($state, FinExpenses $record) => self::money($record, $state)),
                TextColumn::make('min_value')
                    ->label('Valor Mínimo S/R')
                    ->formatStateUsing(fn ($state, FinExpenses $record) => self::money($record, $state)),
                TextColumn::make('operation_type')
                    ->label('Tipo')
                    ->formatStateUsing(fn (?string $state) => self::OPERATION_TYPES[$state] ?? null),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->hidden(fn (FinExpenses $record) => $record->isReferenced()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageExpenses::route('/'),
        ];
    }

    /** Listagem do legado: símbolo da moeda + valor pt-BR, vazio quando o valor é zero. */
    private static function money(FinExpenses $record, mixed $value): ?string
    {
        return $value ? trim($record->currency?->symbol.' '.number_format((float) $value, 2, ',', '.')) : null;
    }
}
