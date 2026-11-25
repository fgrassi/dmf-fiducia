<?php

namespace App\Filament\Resources\PersonExpenses;

use App\Actions\PersonExpenses\SavePersonExpense;
use App\Actions\PersonExpenses\UpdatePersonExpenseValue;
use App\Filament\Resources\BrokerExpenses\BrokerExpenseResource;
use App\Filament\Resources\PersonExpenses\Pages\ManagePersonExpenses;
use App\Filament\Support\ReportFields;
use App\Models\CmnCurrency;
use App\Models\FinExpenses;
use App\Models\MatrizDespesaPessoa;
use App\Models\TipoContainer;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * relatorios/matrizDespesaPessoa (legado): as despesas padrão de clientes e agentes, com filtro e o valor de venda editável na
 * linha. Traz também o cadastro que o legado tinha em "Adicionar Despesa" das telas de pessoa e agente (adicionar, editar e
 * excluir). O relatório fica aberto a quem entra, como no legado; lançar, alterar e excluir pedem a credencial `empresa`, a das
 * telas de pessoa (o legado deixava qualquer usuário trocar o valor).
 */
class PersonExpenseResource extends Resource
{
    /** MatrizDespesaPessoa::getTypeName (legado). */
    public const TYPES = ['i' => 'Importação', 'e' => 'Exportação', 'b' => 'Importação/Exportação'];

    protected static ?string $model = MatrizDespesaPessoa::class;

    protected static ?string $modelLabel = 'Despesa';

    protected static ?string $pluralModelLabel = 'Matriz Despesa Pessoa';

    protected static ?string $slug = 'relatorios/matriz-despesa-pessoa';

    protected static string|UnitEnum|null $navigationGroup = 'Relatórios';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-currency-dollar';

    public static function canManage(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('empresa');
    }

    public static function canCreate(): bool
    {
        return self::canManage();
    }

    public static function canEdit($record): bool
    {
        return self::canManage();
    }

    public static function canDelete($record): bool
    {
        return self::canManage();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                ReportFields::person('pessoa_id', 'Cliente / Agente')
                    ->required()
                    ->disabledOn('edit'),
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
                Select::make('billing')
                    ->label('Tipo de cobrança')
                    ->options(BrokerExpenseResource::BILLINGS)
                    ->required(),
                Select::make('impor_expor')
                    ->label('Tipo de reserva')
                    ->options(self::TYPES)
                    ->required(),
                Select::make('ref_equipamento')
                    ->label('Equipamento')
                    ->options(fn () => TipoContainer::orderBy('nome')->pluck('nome', 'tipo_container_id')),
                ReportFields::port('porto_id', 'Porto'),
                Select::make('ref_currency')
                    ->label('Moeda Venda (S/R)')
                    ->options(fn () => CmnCurrency::orderBy('name')->pluck('symbol', 'id'))
                    ->required(),
                TextInput::make('expense_value')
                    ->label('Valor de Venda (S/R)')
                    ->numeric()
                    ->required(),
                TextInput::make('min_value')
                    ->label('Valor mínimo (S/R)')
                    ->numeric(),
                Select::make('moeda_compra_id')
                    ->label('Moeda Compra (B/R)')
                    ->options(fn () => CmnCurrency::orderBy('name')->pluck('symbol', 'id')),
                TextInput::make('valor_compra')
                    ->label('Valor de Compra (B/R)')
                    ->numeric(),
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
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->select('matriz_despesa_pessoa.*')
                ->leftJoin('pessoa', 'pessoa.pessoa_id', '=', 'matriz_despesa_pessoa.pessoa_id')
                ->leftJoin('fin_expenses', 'fin_expenses.id', '=', 'matriz_despesa_pessoa.ref_expense')
                ->orderBy('pessoa.nome')
                ->orderBy('fin_expenses.name')
                ->with(['pessoa', 'expense', 'equipment', 'port', 'currency']))
            ->emptyStateHeading('Nenhum registro encontrado')
            ->paginated([25, 50, 100])
            ->filters([
                Filter::make('client')
                    ->label('Nome Cliente')
                    ->schema([
                        TextInput::make('client')
                            ->label('Nome Cliente'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query->when($data['client'] ?? null, fn ($q, string $name) => $q->where('pessoa.nome', 'like', "%{$name}%"))),
                Filter::make('expense')
                    ->label('Nome da Despesa')
                    ->schema([
                        TextInput::make('expense')
                            ->label('Nome da Despesa'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query->when($data['expense'] ?? null, fn ($q, string $name) => $q->where('fin_expenses.name', 'like', "%{$name}%"))),
                SelectFilter::make('impor_expor')
                    ->label('Tipo (Impo/Expo)')
                    ->multiple()
                    ->options(self::TYPES),
                SelectFilter::make('ref_equipamento')
                    ->label('Equipamentos')
                    ->multiple()
                    ->options(fn () => TipoContainer::orderBy('nome')->pluck('nome', 'tipo_container_id')),
                Filter::make('port')
                    ->label('Nome do Porto')
                    ->schema([
                        TextInput::make('port')
                            ->label('Nome do Porto'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query->when($data['port'] ?? null, fn ($q, string $name) => $q->whereHas('port', fn ($port) => $port->where('nome', 'like', "%{$name}%")))),
                Filter::make('only_current')
                    ->label('Exibir apenas vigentes')
                    ->toggle()
                    ->default()
                    ->query(fn (Builder $query) => $query
                        ->where(fn ($q) => $q->whereNull('matriz_despesa_pessoa.due_from')->orWhere('matriz_despesa_pessoa.due_from', '<=', today()->toDateString()))
                        ->where(fn ($q) => $q->whereNull('matriz_despesa_pessoa.due_to')->orWhere('matriz_despesa_pessoa.due_to', '>=', today()->toDateString()))),
            ])
            ->columns([
                TextColumn::make('pessoa.nome')
                    ->label('Cliente'),
                TextColumn::make('expense.name')
                    ->label('Despesa'),
                TextColumn::make('impor_expor')
                    ->label('Tipo')
                    ->formatStateUsing(fn (?string $state) => self::TYPES[$state] ?? null),
                TextColumn::make('billing')
                    ->label('Cobrança')
                    ->formatStateUsing(fn (?string $state) => BrokerExpenseResource::BILLINGS[$state] ?? null),
                TextColumn::make('equipment.nome')
                    ->label('Equipamento'),
                TextColumn::make('port.nome')
                    ->label('Porto'),
                TextColumn::make('currency.symbol')
                    ->label('Moeda'),
                TextInputColumn::make('expense_value')
                    ->label('Valor')
                    ->type('number')
                    ->step(0.01)
                    ->rules(['required', 'numeric', 'min:0'])
                    ->disabled(fn () => ! self::canManage())
                    ->updateStateUsing(function (MatrizDespesaPessoa $record, $state) {
                        app(UpdatePersonExpenseValue::class)($record, (float) $state, auth()->user());

                        return $state;
                    }),
                TextColumn::make('due_from')
                    ->label('Vigência de')
                    ->date('d/m/Y'),
                TextColumn::make('due_to')
                    ->label('Vigência até')
                    ->date('d/m/Y'),
            ])
            ->recordActions([
                EditAction::make()
                    ->using(fn (MatrizDespesaPessoa $record, array $data) => app(SavePersonExpense::class)($data, auth()->user(), $record)),
                DeleteAction::make()
                    ->modalDescription('Confirmar exclusão?'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePersonExpenses::route('/'),
        ];
    }
}
