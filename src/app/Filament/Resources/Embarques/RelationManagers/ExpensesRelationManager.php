<?php

namespace App\Filament\Resources\Embarques\RelationManagers;

use App\Actions\Booking\CalculateAgreedFreight;
use App\Actions\Booking\DeleteExpense;
use App\Actions\Booking\ExpenseTracking;
use App\Actions\Booking\ImportQuotationCosts;
use App\Actions\Booking\Notes\CreateNote;
use App\Actions\Booking\SaveExpense;
use App\Actions\Booking\SellCost;
use App\Actions\Booking\ToggleExpenseReview;
use App\Actions\Cobranca\Generation\GenerateManualCharge;
use App\Actions\PaymentOrders\Generation\GeneratePaymentOrders;
use App\Actions\PaymentOrders\Other\GenerateOtherPaymentOrders;
use App\Enums\NoteType;
use App\Models\CmnCurrency;
use App\Models\FinExpenses;
use App\Models\FinExpensesValues;
use App\Models\FinExpensesValuesDueTo;
use Carbon\Carbon;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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

    /** Tabela de despesas + resumo de custos e receitas, obs. e frete acordado abaixo (como no legado). */
    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getTabsContentComponent(),
                RenderHook::make(PanelsRenderHook::RESOURCE_RELATION_MANAGER_BEFORE),
                EmbeddedTable::make(),
                RenderHook::make(PanelsRenderHook::RESOURCE_RELATION_MANAGER_AFTER),
                View::make('filament.embarques.expense-summary'),
            ]);
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

    /** executeGeraDebitNote / executeGeraCreditNote (legado): "com marcados gerar", só nas exportações. */
    private function noteBulkAction(NoteType $type): BulkAction
    {
        return BulkAction::make($type === NoteType::Debit ? 'gerarNotaDebito' : 'gerarNotaCredito')
            ->label($type->label())
            ->icon('heroicon-o-document-text')
            ->visible(fn () => $this->getOwnerRecord()->impor_expor === 'e')
            ->requiresConfirmation()
            ->modalHeading('Gerar '.$type->label())
            ->modalDescription(fn () => $this->hasNotes()
                ? 'Já existem Notas lançadas neste processo. Tem certeza que deseja criar uma nova?'
                : 'A nota será gerada com as despesas selecionadas.')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records) use ($type) {
                try {
                    $id = app(CreateNote::class)($this->getOwnerRecord(), $type, $records->modelKeys(), auth()->id());
                } catch (DomainException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title(DB::table($type->table())->where('id', $id)->value('name').' gerada.')->success()->send();
            });
    }

    private function hasNotes(): bool
    {
        return $this->getOwnerRecord()->debitNotes()->exists() || $this->getOwnerRecord()->creditNotes()->exists();
    }

    private function expenseLabel(FinExpenses $expense): string
    {
        return $expense->label
            .($expense->min_value_br ? ' (B/R: '.number_format($expense->min_value_br, 2, ',', '.').')' : '')
            .($expense->min_value ? ' (S/R: '.number_format($expense->min_value, 2, ',', '.').')' : '');
    }

    /** Na tela do legado, "Cobrança Manual" só aparece para gerente, administrador e financeiro. */
    private function canGenerateCharge(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential(['manager', 'financial']);
    }

    /**
     * geraCobrancaManual (legado): sem despesas marcadas, gera com todas as pendentes da reserva.
     *
     * @param  ?list<int>  $expenseIds
     */
    private function generateCharge(?array $expenseIds, Action $action): void
    {
        try {
            $created = app(GenerateManualCharge::class)($this->getOwnerRecord(), $expenseIds, auth()->user());
        } catch (DomainException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
            $action->halt();
        }

        $created
            ? Notification::make()->title($created === 1 ? '1 cobrança gerada com sucesso!' : "{$created} cobranças geradas com sucesso!")->success()->send()
            : Notification::make()
                ->title('Nenhuma cobrança gerada!')
                ->body('Os motivos (despesas, cotações, liberação, invoice ou tomador) foram enviados por e-mail ao financeiro.')
                ->warning()
                ->send();
    }

    /** Detalhes (ícone de informação da linha): quem criou e quem atualizou, e quando. */
    private function audit(FinExpensesValues $expense): string
    {
        $date = fn (?string $value) => $value ? Carbon::parse($value)->format('d/m/Y') : '';
        $text = "Criado por {$expense->created_by_name} em {$date($expense->created_at)}";

        return $expense->updated_by ? "{$text}\nAtualizado por {$expense->updated_by_name} em {$date($expense->updated_at)}" : $text;
    }

    /** Aviso da linha: mesma moeda e venda menos custo abaixo de 100. */
    private function hasLowMargin(FinExpensesValues $expense): bool
    {
        return $expense->selling_rate - $expense->buying_rate < 100
            && $expense->ref_currency_br == $expense->ref_currency_sr
            && $expense->ref_currency_br
            && $expense->selling_rate;
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
                ->addSelect([
                    'container_number' => DB::table('container')->select('numero')->whereColumn('container.container_id', 'fin_expenses_values.ref_container'),
                    'created_by_name' => DB::table('usuario')->select('nome')->whereColumn('usuario.usuario_id', 'fin_expenses_values.created_by'),
                    'updated_by_name' => DB::table('usuario')->select('nome')->whereColumn('usuario.usuario_id', 'fin_expenses_values.updated_by'),
                ])
                ->with(['despesa.unity', 'dueTo', 'bl', 'equipment', 'buyingCurrency', 'sellingCurrency'])
                ->orderBy('fin_expenses_values.id'))
            ->paginated(false)
            ->headerActions([
                Action::make('gerarCobrancaTodas')
                    ->label('Gerar cobrança (todas as pendentes)')
                    ->icon('heroicon-o-document-plus')
                    ->color('gray')
                    ->visible(fn () => $this->canGenerateCharge())
                    ->requiresConfirmation()
                    ->modalHeading('Gerar cobrança manualmente')
                    ->modalDescription('A IC é gerada com todas as despesas pendentes da reserva e o documento é enviado ao cliente.')
                    ->action(fn (Action $action) => $this->generateCharge(null, $action)),
                Action::make('revisao')
                    ->label(fn () => $this->getOwnerRecord()->revisao_despesas === 's' ? 'Revisado (desfazer)' : 'Marcar como revisado')
                    ->icon(fn () => $this->getOwnerRecord()->revisao_despesas === 's' ? 'heroicon-o-check-badge' : 'heroicon-o-clipboard-document-check')
                    ->color(fn () => $this->getOwnerRecord()->revisao_despesas === 's' ? 'success' : 'gray')
                    ->visible(fn () => ToggleExpenseReview::applies($this->getOwnerRecord()))
                    ->requiresConfirmation()
                    ->action(function (Action $action) {
                        try {
                            $reviewed = app(ToggleExpenseReview::class)($this->getOwnerRecord(), auth()->id());
                        } catch (DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                            $action->halt();
                        }

                        Notification::make()->title($reviewed ? 'Despesas revisadas.' : 'Revisão desfeita.')->success()->send();
                    }),
                Action::make('importarCustosCotacao')
                    ->label('Importar custos da cotação')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->visible(fn () => (bool) ($this->getOwnerRecord()->cotacao_expo_id ?: $this->getOwnerRecord()->cotacao_impo_id ?: $this->getOwnerRecord()->cotacao_impo_aereo_id))
                    ->requiresConfirmation()
                    ->modalHeading('Deseja importar os custos da cotação?')
                    ->modalDescription(fn () => app(ImportQuotationCosts::class)->warning($this->getOwnerRecord()))
                    ->action(function (Action $action) {
                        try {
                            $count = app(ImportQuotationCosts::class)($this->getOwnerRecord(), auth()->id());
                        } catch (DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                            $action->halt();
                        }

                        Notification::make()->title("{$count} despesa(s) importada(s) da cotação.")->success()->send();
                    }),
                Action::make('valorHouseBl')
                    ->label('Valor do House BL')
                    ->icon('heroicon-o-clipboard-document')
                    ->color('gray')
                    ->visible(fn () => $this->agreedFreight() !== null)
                    ->modalHeading('Valor do House BL')
                    ->modalDescription(fn () => 'Gerado em '.Carbon::parse($this->agreedFreight()->created_at)->format('d/m/Y').' por '.DB::table('usuario')->where('usuario_id', $this->agreedFreight()->created_by)->value('nome').'.')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fechar')
                    ->fillForm(fn () => ['valor' => trim($this->agreedFreight()->valor)])
                    ->schema([Textarea::make('valor')->label('Valor')->readOnly()->rows(8)]),
                CreateAction::make()
                    ->label('Adicionar despesa')
                    ->modalWidth('5xl')
                    ->using(fn (array $data, CreateAction $action) => $this->save(null, $data, $action)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('gerarOrdemPagamento')
                        ->label('Ordem de Pagamento')
                        ->icon('heroicon-o-credit-card')
                        ->visible(fn () => (bool) auth()->user()?->hasLegacyCredential('financial'))
                        ->requiresConfirmation()
                        ->modalHeading('Tem certeza que deseja gerar a ordem de pagamento?')
                        ->modalDescription('A ordem de pagamento do armador será gerada para as despesas selecionadas.')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records, BulkAction $action) {
                            try {
                                $result = app(GeneratePaymentOrders::class)->fromSelection($this->getOwnerRecord(), $records->modelKeys());
                            } catch (DomainException $e) {
                                Notification::make()->title($e->getMessage())->danger()->send();
                                $action->halt();
                            }

                            $ignored = collect($result['ignored'])->map(fn (array $i) => "Despesa {$i['id']}: {$i['reason']}")->implode("\n");

                            Notification::make()
                                ->title($result['message'])
                                ->body($ignored !== '' ? $ignored : null)
                                ->status($result['success'] ? 'success' : 'warning')
                                ->send();
                        }),
                    BulkAction::make('gerarOrdemPagamentoOutros')
                        ->label('Ordem de Pagamento Outros')
                        ->icon('heroicon-o-banknotes')
                        ->visible(fn () => (bool) auth()->user()?->hasLegacyCredential('financial'))
                        ->requiresConfirmation()
                        ->modalHeading('Tem certeza que deseja gerar a ordem de pagamento?')
                        ->modalDescription('A ordem de pagamento de outros será gerada para as despesas selecionadas.')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records, BulkAction $action) {
                            try {
                                $result = app(GenerateOtherPaymentOrders::class)->fromSelection($this->getOwnerRecord(), $records->modelKeys());
                            } catch (DomainException $e) {
                                Notification::make()->title($e->getMessage())->danger()->send();
                                $action->halt();
                            }

                            $ignored = collect($result['ignored'])->map(fn (array $i) => "Despesa {$i['id']}: {$i['reason']}")->implode("\n");

                            Notification::make()
                                ->title($result['message'])
                                ->body($ignored !== '' ? $ignored : null)
                                ->status($result['success'] ? 'success' : 'warning')
                                ->send();
                        }),
                    BulkAction::make('calcularFrete')
                        ->label('Calcular Frete')
                        ->icon('heroicon-o-calculator')
                        ->visible(fn () => $this->getOwnerRecord()->impor_expor === 'i')
                        ->modalHeading('Calcular o valor do House BL')
                        ->modalDescription('O valor é calculado com as despesas marcadas.')
                        ->schema(fn () => $this->agreedFreight() ? [Textarea::make('justificativa')->label('Justificativa')->required()] : [])
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records, array $data, BulkAction $action) {
                            try {
                                app(CalculateAgreedFreight::class)($this->getOwnerRecord(), $records->modelKeys(), $data['justificativa'] ?? null, auth()->id());
                            } catch (DomainException $e) {
                                Notification::make()->title($e->getMessage())->danger()->send();
                                $action->halt();
                            }

                            Notification::make()->title('Valor do House BL calculado.')->success()->send();
                        }),
                    BulkAction::make('gerarCobrancaManual')
                        ->label('Gerar cobrança manual')
                        ->icon('heroicon-o-document-plus')
                        ->visible(fn () => $this->canGenerateCharge())
                        ->requiresConfirmation()
                        ->modalHeading('Gerar cobrança manualmente')
                        ->modalDescription('A IC é gerada só com as despesas marcadas e o documento é enviado ao cliente.')
                        ->deselectRecordsAfterCompletion()
                        ->action(fn (Collection $records, BulkAction $action) => $this->generateCharge($records->modelKeys(), $action)),
                    $this->noteBulkAction(NoteType::Debit),
                    $this->noteBulkAction(NoteType::Credit),
                ]),
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
                Action::make('venderCusto')
                    ->label('Vender custo')
                    ->icon('heroicon-o-arrows-right-left')
                    ->iconButton()
                    ->requiresConfirmation()
                    ->modalHeading('Tem certeza de que deseja igualar o custo da venda com o da compra?')
                    ->action(function (FinExpensesValues $record) {
                        app(SellCost::class)($record);
                        Notification::make()->title('Venda igualada ao custo.')->success()->send();
                    }),
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
                IconColumn::make('info')
                    ->label('')
                    ->state('info')
                    ->icon('heroicon-o-information-circle')
                    ->color('danger')
                    ->tooltip(fn (FinExpensesValues $record) => $this->audit($record)),
                IconColumn::make('low_margin')
                    ->label('')
                    ->state(fn (FinExpensesValues $record) => $this->hasLowMargin($record) ? 'warning' : null)
                    ->icon('heroicon-o-exclamation-triangle')
                    ->color('danger')
                    ->tooltip('A diferença entre o B/R e S/R é inferior a 100,00 na mesma moeda!'),
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

    /** valor_frete_embarque: o valor do House BL calculado para a reserva, se houver. */
    private function agreedFreight(): ?object
    {
        return DB::table('valor_frete_embarque')->where('embarque_id', $this->getOwnerRecord()->getKey())->first();
    }
}
