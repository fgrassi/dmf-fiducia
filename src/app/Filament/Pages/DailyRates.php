<?php

namespace App\Filament\Pages;

use App\Actions\Financeiro\DailyRatesReport;
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
 * cotacao/index (legado), "Consulta Cotação Diária" (menu 308), no menu Consultas: valor de cada cotação e taxa de armador por dia, no período.
 */
class DailyRates extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = 'Consulta Cotação Diária';

    protected static ?string $navigationLabel = 'Consulta Cotação Diária';

    protected static ?string $slug = 'financeiro/consulta-cotacao-diaria';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationParentItem = 'Consultas';

    protected static ?int $navigationSort = 10;

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
            ->records(fn (int $page, int|string $recordsPerPage, ?string $sortColumn, ?string $sortDirection) => ReportRecords::paginate(
                array_map(fn (array $row) => ['date' => $row['date']] + array_combine(array_map(fn (int $index) => "v{$index}", array_keys($row['values'])), $row['values']), $this->report($this->tableFilters['periodo'] ?? [])['rows']),
                $page,
                $recordsPerPage,
                $sortColumn,
                $sortDirection,
            ))
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->defaultSort('date', 'desc')
            ->emptyStateHeading('Nenhuma cotação no período informado')
            ->filtersLayout(FiltersLayout::AboveContent)
            ->deferFilters()
            ->filtersApplyAction(fn (Action $action) => $action->label('Enviar'))
            ->filtersResetAction(fn (Action $action) => $action->label('Limpar'))
            ->filters([
                Filter::make('periodo')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 2])
                            ->schema([
                                DatePicker::make('data_ini')
                                    ->label('Data inicial')
                                    ->default(now()->subMonthNoOverflow()),
                                DatePicker::make('data_fim')
                                    ->label('Data final')
                                    ->default(now())
                                    ->afterOrEqual('data_ini'),
                            ]),
                    ]),
            ])
            ->columns([
                TextColumn::make('date')
                    ->label('Data')
                    ->sortable()
                    ->date('d/m/Y'),
                ...$this->rateColumns(),
            ]);
    }

    /**
     * Uma coluna por cotação e taxa de armador, no cabeçalho "nome · moeda". Elas não dependem do período: saem do relatório de um dia.
     *
     * @return list<TextColumn>
     */
    private function rateColumns(): array
    {
        $columns = $this->report(['data_ini' => now()->toDateString(), 'data_fim' => now()->toDateString()])['columns'];

        return array_map(fn (array $column, int $index) => TextColumn::make("v{$index}")
            ->label(trim($column['name'].' · '.$column['currency'], ' ·'))
            ->alignEnd()
            ->formatStateUsing(fn ($state) => $state === null ? '' : number_format((float) $state, 4, ',', '.')), $columns, array_keys($columns));
    }

    /**
     * @param  array{data_ini?: ?string, data_fim?: ?string}  $filters
     * @return array{columns: list<array{name: string, currency: string}>, rows: list<array{date: string, values: list<?float>}>}
     */
    private function report(array $filters): array
    {
        return app(DailyRatesReport::class)([
            'data_ini' => $filters['data_ini'] ?? now()->subMonthNoOverflow()->toDateString(),
            'data_fim' => $filters['data_fim'] ?? now()->toDateString(),
        ]);
    }
}
