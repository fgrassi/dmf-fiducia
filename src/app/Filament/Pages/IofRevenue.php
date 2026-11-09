<?php

namespace App\Filament\Pages;

use App\Actions\Financeiro\IofRevenueReport;
use App\Filament\Support\ReportRecords;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
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
 * relatorioIof (legado), "Arrecadação IOF": IOF recebido por reserva e HBL, pela data de recebimento da cobrança. Fica no
 * menu Relatórios.
 */
class IofRevenue extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = 'Arrecadação IOF';

    protected static ?string $navigationLabel = 'Arrecadação IOF';

    protected static ?string $slug = 'financeiro/relatorios/arrecadacao-iof';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationParentItem = 'Relatórios';

    protected static ?int $navigationSort = 18;

    /** Relatório da requisição: a tabela e o total leem o mesmo resultado. */
    protected ?array $report = null;

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

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (int $page, int|string $recordsPerPage, ?string $sortColumn, ?string $sortDirection) => ReportRecords::paginate($this->report()['rows'], $page, $recordsPerPage, $sortColumn, $sortDirection))
            ->description(fn () => 'Total de IOF: R$ '.number_format($this->report()['total'], 2, ',', '.'))
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading('Nenhum recibo com IOF no período informado')
            ->filtersLayout(FiltersLayout::AboveContent)
            ->deferFilters()
            ->filtersApplyAction(fn (Action $action) => $action->label('Filtrar'))
            ->filtersResetAction(fn (Action $action) => $action->label('Limpar'))
            ->filters([
                Filter::make('periodo')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 2])
                            ->schema([
                                DatePicker::make('data_ini')
                                    ->label('Data inicial')
                                    ->default(now()->startOfMonth()),
                                DatePicker::make('data_fim')
                                    ->label('Data final')
                                    ->default(now()->endOfMonth()),
                            ]),
                    ]),
            ])
            ->columns([
                TextColumn::make('booking')
                    ->label('Reserva')
                    ->sortable(),
                TextColumn::make('bl')
                    ->label('HBL')
                    ->sortable(),
                TextColumn::make('valor_iof')
                    ->label('IOF (R$)')
                    ->alignEnd()
                    ->sortable()
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2, ',', '.')),
                TextColumn::make('payment_date')
                    ->label('Data Recebimento')
                    ->sortable()
                    ->date('d/m/Y'),
            ]);
    }

    /** @return array{rows: list<array<string, mixed>>, total: float} */
    private function report(): array
    {
        return $this->report ??= app(IofRevenueReport::class)($this->tableFilters['periodo'] ?? []);
    }
}
