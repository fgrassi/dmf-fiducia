<?php

namespace App\Filament\Pages;

use App\Actions\Contabilidade\CostsToAccrueReport;
use App\Actions\Contabilidade\ExportAccountingReport;
use App\Actions\Contabilidade\IssuedInvoiceCostsReport;
use App\Actions\Contabilidade\ReceivableClientsReport;
use App\Actions\Contabilidade\ReportPeriod;
use App\Filament\Support\ReportRecords;
use BackedEnum;
use Carbon\Carbon;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * contabilidade/index (legado), "Relatórios Contabilidade": Clientes a Receber, Pagos e não recebidos e Custos das Notas Emitidas no
 * mês, sempre num período (as duas datas são obrigatórias), com Excel. Fica no menu Relatórios.
 */
class AccountingReports extends Page implements HasTable
{
    use InteractsWithTable;

    /** Relatório => [nome, Action, título do Excel, colunas (chave => [título, tipo])]. */
    private const REPORTS = [
        'receber' => [
            'Clientes a Receber', ReceivableClientsReport::class, 'Clientes a Receber', [
                'cliente' => ['Cliente', 'text'],
                'reserva_data' => ['Reserva Data', 'date'],
                'reserva_numero' => ['Reserva Número', 'text'],
                'nf_data' => ['NF Data', 'date'],
                'nf_numero' => ['NF Número', 'text'],
                'valor' => ['R$ a Receber', 'money'],
            ],
        ],
        'apropriar' => [
            'Pagos e não recebidos', CostsToAccrueReport::class, 'Custos a Apropriar - Pagos e não recebidos', [
                'cliente' => ['Cliente', 'text'],
                'reserva_data' => ['Reserva Data', 'date'],
                'reserva_numero' => ['Reserva Número', 'text'],
                'pago' => ['R$ Pago', 'money'],
                'apropriado' => ['R$ Apropriado', 'money'],
                'saldo' => ['(=) Saldo', 'money'],
            ],
        ],
        'notas' => [
            'Notas emitidas no mês', IssuedInvoiceCostsReport::class, 'Custos das Notas Emitidas no mês', [
                'nf_data' => ['Data NF', 'date'],
                'nf_numero' => ['Número NF', 'text'],
                'reserva_numero' => ['Número Reserva', 'text'],
                'cliente' => ['Cliente', 'text'],
                'usd' => ['USD', 'money'],
                'eur' => ['Euro', 'money'],
                'brl' => ['R$', 'money'],
            ],
        ],
    ];

    protected static ?string $title = 'Relatórios Contabilidade';

    protected static ?string $navigationLabel = 'Relatórios Contabilidade';

    protected static ?string $slug = 'financeiro/relatorios/contabilidade';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationParentItem = 'Relatórios';

    protected static ?int $navigationSort = 23;

    /** Relatório da requisição: a tabela, o total e o Excel leem o mesmo resultado. */
    protected ?array $report = null;

    /** Aviso do período inválido (as duas datas são obrigatórias). */
    protected ?string $error = null;

    /** security.yml do módulo (legado): credencial financial. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('financial');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                EmbeddedTable::make(),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Exportar XLS')
                ->color('gray')
                ->action(function () {
                    $report = $this->report();

                    if ($this->error()) {
                        Notification::make()->title($this->error())->warning()->send();

                        return null;
                    }

                    [, , $title, $columns] = self::REPORTS[$this->kind()];

                    return app(ExportAccountingReport::class)($title.' - '.$report['period'], $columns, $report['rows'], $report['totals']);
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (int $page, int|string $recordsPerPage, ?string $sortColumn, ?string $sortDirection) => ReportRecords::paginate($this->report()['rows'], $page, $recordsPerPage, $sortColumn, $sortDirection))
            ->description(fn () => $this->error() ?? 'Período: '.$this->report()['period'].' — '.$this->totalsLabel())
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading(fn () => $this->error() ?? 'Nenhum registro encontrado para o período.')
            ->filtersLayout(FiltersLayout::AboveContent)
            ->deferFilters()
            ->filtersApplyAction(fn (Action $action) => $action->label('Buscar'))
            ->filtersResetAction(fn (Action $action) => $action->label('Limpar'))
            ->filters([
                Filter::make('periodo')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 3])
                            ->schema([
                                Select::make('relatorio')
                                    ->label('Relatório')
                                    ->options(array_map(fn (array $report) => $report[0], self::REPORTS))
                                    ->default('receber')
                                    ->required()
                                    ->selectablePlaceholder(false),
                                DatePicker::make('data_ini')
                                    ->label('Data Inicial')
                                    ->default(now()->startOfMonth()),
                                DatePicker::make('data_fim')
                                    ->label('Data Final')
                                    ->default(now()->endOfMonth()),
                            ]),
                    ]),
            ])
            ->columns(array_map(fn (string $key, string $type) => TextColumn::make($key)
                // A tabela é montada antes de o filtro ser aplicado: rótulo e visibilidade saem do relatório escolhido na hora de desenhar.
                ->label(fn () => self::REPORTS[$this->kind()][3][$key][0] ?? $key)
                ->visible(fn () => isset(self::REPORTS[$this->kind()][3][$key]))
                ->sortable()
                ->alignment($type === 'money' ? 'end' : null)
                ->formatStateUsing(fn ($state) => match ($type) {
                    'money' => number_format((float) $state, 2, ',', '.'),
                    'date' => filled($state) ? Carbon::parse($state)->format('d/m/Y') : '',
                    default => $state,
                }), array_keys(self::columnTypes()), self::columnTypes()));
    }

    /** @return array<string, string> coluna => tipo, de todos os relatórios (o tipo é o mesmo onde a coluna se repete) */
    private static function columnTypes(): array
    {
        return array_merge(...array_map(fn (array $report) => array_map(fn (array $column) => $column[1], $report[3]), array_values(self::REPORTS)));
    }

    private function error(): ?string
    {
        $this->report();

        return $this->error;
    }

    private function kind(): string
    {
        $kind = $this->tableFilters['periodo']['relatorio'] ?? null;

        return isset(self::REPORTS[$kind]) ? $kind : 'receber';
    }

    /** @return array{rows: list<array<string, mixed>>, totals: array<string, float>, period: string} */
    private function report(): array
    {
        if ($this->report !== null) {
            return $this->report;
        }

        $input = $this->tableFilters['periodo'] ?? [];

        try {
            $result = app(self::REPORTS[$this->kind()][1])($input);
            $this->error = null;
        } catch (DomainException $e) {
            $this->error = $e->getMessage();
            $result = ['rows' => [], 'totals' => []];
        }

        return $this->report = $result + ['period' => $this->error ? '' : ReportPeriod::label(...ReportPeriod::parse($input))];
    }

    private function totalsLabel(): string
    {
        [, , , $columns] = self::REPORTS[$this->kind()];

        return collect($this->report()['totals'])
            ->map(fn ($total, $key) => $columns[$key][0].': '.number_format($total, 2, ',', '.'))
            ->implode(' | ');
    }
}
