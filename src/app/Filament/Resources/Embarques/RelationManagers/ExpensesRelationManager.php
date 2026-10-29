<?php

namespace App\Filament\Resources\Embarques\RelationManagers;

use App\Actions\Booking\DeleteExpense;
use App\Actions\Booking\ExpenseTracking;
use App\Actions\Booking\SaveExpense;
use App\Models\CmnCurrency;
use App\Models\FinExpenses;
use App\Models\FinExpensesValues;
use App\Models\FinExpensesValuesDueTo;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Aba de despesas da reserva (embarqueExpenses / updateEmbarqueExpenses do legado). */
class ExpensesRelationManager extends RelationManager
{
    protected static string $relationship = 'expenses';

    protected static ?string $title = 'Despesas';

    private const BILLING = ExpenseTracking::BILLING;

    private const CHILE = 43;

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        $booking = $this->getOwnerRecord();
        $hidden = fn (Get $get) => (int) $get('ref_expense') === SaveExpense::HIDDEN_FLAGS_EXPENSE;
        $airImport = $booking->impor_expor === 'i' && $booking->tipo_embarque === 'a';
        $currencies = fn () => CmnCurrency::query()->orderBy('name')->get()->mapWithKeys(fn (CmnCurrency $c) => [$c->id => "{$c->symbol} - {$c->name}"]);

        return $schema
            ->components([
                Grid::make(3)
                    ->schema([
                        Select::make('ref_expense')
                            ->label('Despesa')
                            ->required()
                            ->searchable()
                            ->live()
                            ->getSearchResultsUsing(fn (string $search) => FinExpenses::query()
                                ->with('unity')
                                ->where('name', 'like', "%{$search}%")
                                ->orderBy('name')
                                ->limit(50)
                                ->get()
                                ->mapWithKeys(fn (FinExpenses $e) => [$e->id => $this->expenseLabel($e)]))
                            ->getOptionLabelUsing(fn ($value) => ($e = FinExpenses::with('unity')->find($value)) ? $this->expenseLabel($e) : null)
                            ->afterStateUpdated(function (Set $set, $state) {
                                if ((int) $state !== SaveExpense::HIDDEN_FLAGS_EXPENSE) {
                                    return;
                                }

                                $set('ref_due_to', SaveExpense::DMF_DUE_TO);
                                $set('billing', 'p');
                                $set('ctrl3', true);
                                $set('all_in', true);
                                $set('agreement', false);

                                foreach (['ref_currency_sr', 'selling_rate', 'sr_min', 'selling_rate_perc', 'profit'] as $field) {
                                    $set($field, null);
                                }
                            }),
                        Select::make('ref_equipment')
                            ->label('Equipamento')
                            ->placeholder('TODOS')
                            ->options(fn () => DB::table('tipo_container')
                                ->join('mercadoria', 'mercadoria.tipo_container_id', '=', 'tipo_container.tipo_container_id')
                                ->where('mercadoria.embarque_id', $booking->getKey())
                                ->distinct()
                                ->orderBy('tipo_container.nome')
                                ->pluck('tipo_container.nome', 'tipo_container.tipo_container_id')),
                        Select::make('ref_due_to')
                            ->label('Due To')
                            ->required()
                            ->disabled($hidden)
                            ->options(fn () => FinExpensesValuesDueTo::query()->orderBy('name')->pluck('name', 'id')),
                        Select::make('ref_bl')
                            ->label('BL')
                            ->options(fn () => $booking->bls()->whereNotNull('bl')->orderBy('bl')->pluck('bl', 'bl_id')),
                        Select::make('ref_container')
                            ->label('Container (BL)')
                            ->options(fn () => DB::table('container')
                                ->join('bl', 'bl.bl_id', '=', 'container.bl_id')
                                ->where('bl.embarque_id', $booking->getKey())
                                ->orderBy('container.numero')
                                ->pluck('container.numero', 'container.container_id')),
                        Select::make('billing')
                            ->label('Tipo')
                            ->required()
                            ->disabled($hidden)
                            ->options(self::BILLING),
                        Select::make('ref_currency_br')
                            ->label('Moeda Custo')
                            ->required()
                            ->options($currencies),
                        TextInput::make('buying_rate')
                            ->label('Custo')
                            ->required()
                            ->numeric(),
                        TextInput::make('br_min')
                            ->label('Custo Mínimo')
                            ->numeric(),
                        Select::make('ref_currency_sr')
                            ->label('Moeda Venda')
                            ->required(fn (Get $get) => ! $airImport && ! $hidden($get))
                            ->disabled($hidden)
                            ->options($currencies),
                        TextInput::make('selling_rate')
                            ->label('Venda')
                            ->required(fn (Get $get) => ! $airImport && ! $hidden($get))
                            ->disabled($hidden)
                            ->numeric(),
                        TextInput::make('sr_min')
                            ->label('Venda Mínima')
                            ->disabled($hidden)
                            ->numeric(),
                        TextInput::make('selling_rate_perc')
                            ->label('Venda (%)')
                            ->disabled($hidden)
                            ->numeric()
                            ->visible($booking->tipo_embarque === 'a'),
                        TextInput::make('profit')
                            ->label('Profit')
                            ->disabled($hidden)
                            ->numeric()
                            ->visible($booking->tipo_embarque === 'a'),
                    ]),
                Grid::make(3)
                    ->schema([
                        Toggle::make('ctrl')
                            ->label('Mostrar na Shipping Instruction (custo)')
                            ->default($booking->impor_expor === 'e'),
                        Toggle::make('ctrl2')
                            ->label('Mostrar na Shipping Instruction (venda)')
                            ->default($booking->impor_expor === 'e'),
                        Toggle::make('ctrl3')
                            ->label('Não mostrar no Booking')
                            ->disabled($hidden),
                        Toggle::make('agreement')
                            ->label('Mostrar S/R como PER AGREEMENT')
                            ->disabled($hidden),
                        Toggle::make('all_in')
                            ->label('Incluso no Frete')
                            ->disabled($hidden),
                        Toggle::make('flag_exw')
                            ->label('EXW')
                            ->visible($booking->impor_expor === 'e'),
                        Toggle::make('mostra_agente')
                            ->label('Informar Agente')
                            ->visible($this->agentIsChilean()),
                    ]),
            ]);
    }

    private function expenseLabel(FinExpenses $expense): string
    {
        return $expense->label
            .($expense->min_value_br ? ' (B/R: '.number_format($expense->min_value_br, 2, ',', '.').')' : '')
            .($expense->min_value ? ' (S/R: '.number_format($expense->min_value, 2, ',', '.').')' : '');
    }

    /** O campo "Informar Agente" só existe quando o país do agente é o Chile. */
    private function agentIsChilean(): bool
    {
        return (int) DB::table('pessoa')->where('pessoa_id', $this->getOwnerRecord()->pessoa_id_representante)->value('paises_id') === self::CHILE;
    }

    /** @param  array<string, mixed>  $data */
    private function save(?FinExpensesValues $record, array $data, Action $action): FinExpensesValues
    {
        try {
            return app(SaveExpense::class)($this->getOwnerRecord(), $data, $record, auth()->id());
        } catch (DomainException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
            $action->halt();
        }
    }

    public function table(Table $table): Table
    {
        $money = fn (string $currency, string $rate) => fn (FinExpensesValues $record) => $record->$rate === null
            ? null
            : trim($record->$currency?->symbol.' '.number_format($record->$rate, 2, ',', '.'));

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->select('fin_expenses_values.*')
                ->addSelect(['container_number' => DB::table('container')
                    ->select('numero')
                    ->whereColumn('container.container_id', 'fin_expenses_values.ref_container')])
                ->with(['despesa.unity', 'dueTo', 'bl', 'equipment', 'buyingCurrency', 'sellingCurrency'])
                ->orderBy('fin_expenses_values.id'))
            ->paginated(false)
            ->headerActions([
                CreateAction::make()
                    ->label('Adicionar despesa')
                    ->modalWidth('5xl')
                    ->using(fn (array $data, CreateAction $action) => $this->save(null, $data, $action)),
            ])
            ->recordActions([
                EditAction::make()
                    ->modalWidth('5xl')
                    ->mutateRecordDataUsing(fn (array $data) => [
                        ...$data,
                        ...collect(['ctrl', 'ctrl2', 'ctrl3', 'agreement', 'all_in', 'flag_exw', 'mostra_agente'])->mapWithKeys(fn (string $flag) => [$flag => (int) ($data[$flag] ?? 0) === 1])->all(),
                        'profit' => $data['selling_rate'] && $data['buying_rate'] ? round($data['selling_rate'] - $data['buying_rate'], 2) : null,
                    ])
                    ->using(fn (FinExpensesValues $record, array $data, EditAction $action) => $this->save($record, $data, $action)),
                DeleteAction::make()
                    ->using(function (FinExpensesValues $record, DeleteAction $action) {
                        try {
                            app(DeleteExpense::class)($record, auth()->id());
                        } catch (DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                            $action->halt();
                        }

                        return true;
                    }),
            ])
            ->columns([
                TextColumn::make('despesa.label')
                    ->label('Despesa'),
                TextColumn::make('equipment.nome')
                    ->label('Equipamento'),
                TextColumn::make('dueTo.name')
                    ->label('Due To'),
                TextColumn::make('bl.bl')
                    ->label('BL'),
                TextColumn::make('container_number')
                    ->label('Container'),
                TextColumn::make('buying_rate')
                    ->label('Custo')
                    ->state($money('buyingCurrency', 'buying_rate')),
                TextColumn::make('br_min')
                    ->label('Custo mín.')
                    ->numeric(2, ',', '.'),
                IconColumn::make('ctrl')
                    ->label('S.I. (custo)')
                    ->boolean(),
                TextColumn::make('selling_rate')
                    ->label('Venda')
                    ->state($money('sellingCurrency', 'selling_rate')),
                TextColumn::make('sr_min')
                    ->label('Venda mín.')
                    ->numeric(2, ',', '.'),
                TextColumn::make('selling_rate_perc')
                    ->label('S/R %')
                    ->numeric(2, ',', '.')
                    ->visible(fn () => $this->getOwnerRecord()->tipo_embarque === 'a'),
                IconColumn::make('ctrl2')
                    ->label('S.I. (venda)')
                    ->boolean(),
                TextColumn::make('billing')
                    ->label('Billing')
                    ->formatStateUsing(fn (?string $state) => self::BILLING[$state] ?? $state),
                IconColumn::make('agreement')
                    ->label('Agr.')
                    ->boolean(),
                IconColumn::make('ctrl3')
                    ->label('Booking')
                    ->boolean(),
                IconColumn::make('all_in')
                    ->label('Incluso no frete')
                    ->boolean(),
                IconColumn::make('flag_exw')
                    ->label('EXW')
                    ->boolean()
                    ->visible(fn () => $this->getOwnerRecord()->impor_expor === 'e'),
            ]);
    }
}
