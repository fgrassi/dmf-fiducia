<?php

namespace App\Filament\Pages;

use App\Actions\Financeiro\ClientAccountStatement as ClientAccountStatementReport;
use App\Actions\Financeiro\ExportClientAccountStatement;
use App\Filament\Livewire\ReportSectionTable;
use App\Filament\Support\ReportRecords;
use App\Models\Pessoa;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Cache;
use UnitEnum;

/**
 * relatorioStatementConta (legado), "Extrato de Conta - Cliente": itens em aberto do cliente na tela e o Excel completo
 * (Info, Statement, Invoices e Payments). Fica no menu Relatórios.
 */
class ClientAccountStatement extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = 'Extrato de Conta - Cliente';

    protected static ?string $navigationLabel = 'Extrato de Conta - Cliente';

    protected static ?string $slug = 'financeiro/relatorios/extrato-conta-cliente';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationParentItem = 'Relatórios';

    protected static ?int $navigationSort = 15;

    /** Relatório da requisição: a tabela, o resumo e o Excel leem o mesmo resultado. */
    protected ?array $report = null;

    /** Aviso de cliente ou data inicial em branco (mesma mensagem do legado). */
    protected ?string $error = null;

    /** security.yml do módulo (legado): financial, director, manager ou supervisor (admin entra em qualquer módulo). */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential(['financial', 'director', 'manager', 'supervisor']);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                EmbeddedTable::make(),
                Livewire::make(ReportSectionTable::class, fn () => ['page' => static::class, 'section' => 'totals', 'filters' => $this->tableFilters['busca'] ?? []])
                    ->key('totals'.md5(json_encode($this->tableFilters['busca'] ?? [])))
                    ->visible(fn () => ! $this->error && filled($this->report())),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('excel')
                ->label('Baixar Excel')
                ->color('gray')
                ->action(function () {
                    $report = $this->report();

                    if ($this->error) {
                        Notification::make()->title($this->error)->warning()->send();

                        return null;
                    }

                    return app(ExportClientAccountStatement::class)($report);
                }),
        ];
    }

    public function table(Table $table): Table
    {
        $money = fn ($state) => 'R$ '.number_format((float) $state, 2, ',', '.');

        return $table
            ->records(fn (int $page, int|string $recordsPerPage, ?string $sortColumn, ?string $sortDirection) => ReportRecords::paginate($this->report()['statement'] ?? [], $page, $recordsPerPage, $sortColumn, $sortDirection))
            ->heading(fn () => $this->error ? null : ($this->report()['cliente_nome'] ?? null))
            ->description(fn () => $this->error ?? $this->summary())
            ->recordClasses(fn (array $record) => (int) $record['days_overdue'] > 0 ? 'bg-red-100 dark:bg-red-950/40' : null)
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading(fn () => $this->error ?? 'Nenhum item em aberto para este cliente')
            ->filtersLayout(FiltersLayout::AboveContent)
            ->deferFilters()
            ->filtersApplyAction(fn (Action $action) => $action->label('Gerar'))
            ->filtersResetAction(fn (Action $action) => $action->label('Limpar'))
            ->filters([
                Filter::make('busca')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 3])
                            ->schema([
                                Select::make('cliente_id')
                                    ->label('Cliente')
                                    ->searchable()
                                    ->getSearchResultsUsing(fn (string $search) => Pessoa::query()
                                        ->where(fn ($query) => $query->where('nome', 'like', "%{$search}%")->orWhere('cnpj', 'like', "%{$search}%"))
                                        ->orderBy('nome')
                                        ->limit(30)
                                        ->get()
                                        ->mapWithKeys(fn (Pessoa $person) => [$person->getKey() => $person->nome.(filled($person->cnpj) ? " ({$person->cnpj})" : '')]))
                                    ->getOptionLabelUsing(fn ($value) => Pessoa::query()->whereKey($value)->value('nome')),
                                DatePicker::make('data_ini')
                                    ->label('Emissão de'),
                                DatePicker::make('data_fim')
                                    ->label('Emissão até')
                                    ->default(today()),
                            ]),
                    ]),
            ])
            ->columns([
                TextColumn::make('id')
                    ->label('Invoice Ref')
                    ->sortable(),
                TextColumn::make('num_nota_fiscal')
                    ->label('NF-e')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Emissão')
                    ->sortable()
                    ->date('d/m/Y'),
                TextColumn::make('due_date')
                    ->label('Vencimento')
                    ->sortable()
                    ->date('d/m/Y'),
                TextColumn::make('booking_ref')
                    ->label('Booking')
                    ->sortable(),
                TextColumn::make('bl_ref')
                    ->label('BL'),
                TextColumn::make('total_value')
                    ->label('Total')
                    ->alignEnd()
                    ->sortable()
                    ->formatStateUsing($money),
                TextColumn::make('received_value')
                    ->label('Recebido')
                    ->alignEnd()
                    ->sortable()
                    ->formatStateUsing($money),
                TextColumn::make('balance_open')
                    ->label('Saldo Aberto')
                    ->alignEnd()
                    ->sortable()
                    ->formatStateUsing($money),
                TextColumn::make('overpayment')
                    ->label('Pago a Maior')
                    ->alignEnd()
                    ->formatStateUsing($money),
                TextColumn::make('match_status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'Fully Matched' => 'success',
                        'Partially Matched' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('days_overdue')
                    ->label('Dias Vencido')
                    ->alignEnd()
                    ->sortable()
                    ->placeholder(''),
            ]);
    }

    /** CNPJ e período de emissão do extrato. */
    private function summary(): ?string
    {
        if (blank($report = $this->report())) {
            return null;
        }

        return "CNPJ: {$report['cliente_cnpj']} | Emissão: ".date('d/m/Y', strtotime($report['data_ini'])).' a '.date('d/m/Y', strtotime($report['data_fim']));
    }

    /**
     * Totais do extrato, sob a tabela: itens em aberto até a data final, invoices e payments do período.
     *
     * @param  array<string, mixed>  $filters
     */
    public static function sectionTable(Table $table, string $section, array $filters): Table
    {
        $money = fn ($state) => filled($state) ? 'R$ '.number_format((float) $state, 2, ',', '.') : '';
        $rows = function () use ($filters): array {
            $report = self::run($filters);
            $statement = $report['statement'] ?? [];
            $sum = fn (string $key) => array_sum(array_column($statement, $key));

            return $statement === [] && ($report['invoices'] ?? []) === [] && ($report['payments'] ?? []) === [] ? [] : [
                'open' => ['label' => 'Itens em aberto até '.date('d/m/Y', strtotime($report['data_fim'])), 'items' => count($statement), 'total' => $sum('total_value'), 'received' => $sum('received_value'), 'open_balance' => $report['totais']['em_aberto'], 'overpayment' => $report['totais']['pago_a_maior']],
                'invoices' => ['label' => 'Invoices no período', 'items' => $report['totais']['invoices'], 'total' => $report['totais']['faturado'], 'received' => $report['totais']['recebido'], 'open_balance' => null, 'overpayment' => null],
                'payments' => ['label' => 'Payments no período', 'items' => $report['totais']['payments'], 'total' => null, 'received' => null, 'open_balance' => null, 'overpayment' => null],
            ];
        };

        return $table
            ->heading('Totais')
            ->records($rows)
            ->emptyStateHeading('Sem totais para o extrato')
            ->columns([
                TextColumn::make('label')
                    ->label('')
                    ->weight(FontWeight::SemiBold),
                TextColumn::make('items')
                    ->label('Itens')
                    ->alignEnd(),
                TextColumn::make('total')
                    ->label('Total')
                    ->alignEnd()
                    ->formatStateUsing($money),
                TextColumn::make('received')
                    ->label('Recebido')
                    ->alignEnd()
                    ->formatStateUsing($money),
                TextColumn::make('open_balance')
                    ->label('Saldo Aberto')
                    ->alignEnd()
                    ->formatStateUsing($money),
                TextColumn::make('overpayment')
                    ->label('Pago a Maior')
                    ->alignEnd()
                    ->formatStateUsing($money),
            ]);
    }

    /**
     * Relatório dos filtros, o mesmo para a tabela, o resumo, os totais e o Excel na requisição.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     *
     * @throws DomainException cliente ou data inicial em branco
     */
    private static function run(array $filters): array
    {
        return Cache::driver('array')->rememberForever('account-statement:'.md5(json_encode($filters)), fn () => app(ClientAccountStatementReport::class)($filters));
    }

    /** @return array<string, mixed> vazio, com o aviso em `$error`, quando falta o cliente ou a data inicial */
    private function report(): array
    {
        if ($this->report !== null) {
            return $this->report;
        }

        try {
            $this->error = null;

            return $this->report = self::run($this->tableFilters['busca'] ?? []);
        } catch (DomainException $e) {
            $this->error = $e->getMessage();

            return $this->report = [];
        }
    }
}
