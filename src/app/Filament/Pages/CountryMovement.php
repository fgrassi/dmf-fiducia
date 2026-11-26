<?php

namespace App\Filament\Pages;

use App\Actions\Reports\CountryMovementReport;
use App\Actions\Reports\ExportSpreadsheet;
use App\Filament\Support\ReportFields;
use App\Filament\Support\ReportRecords;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
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

/** insights/index (legado), "Movimento por País": embarques e contêineres por país no período da saída prevista. */
class CountryMovement extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = 'Movimento por País';

    protected static ?string $navigationLabel = 'Movimento por País';

    protected static ?string $slug = 'relatorios/movimento-por-pais';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAmericas;

    protected static string|UnitEnum|null $navigationGroup = 'Relatórios';

    protected static ?int $navigationSort = 42;

    /** @var array{rows: list<array<string, mixed>>, shipments: int, containers: int}|null */
    protected ?array $report = null;

    /** security.yml do módulo (legado): credencial director. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('director');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Exportar XLS')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->visible(fn () => $this->report()['rows'] !== [])
                ->action(fn () => app(ExportSpreadsheet::class)(
                    'Movimento por País',
                    ['País', 'Embarques', '% Embarques', 'Containers', '% Containers'],
                    array_map(fn (array $row) => [$row['country'], $row['shipments'], round($row['shipmentsPercent'], 2), $row['containers'], round($row['containersPercent'], 2)], $this->report()['rows']),
                )),
        ];
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
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->description(fn () => $this->report()['rows'] === [] ? null : "Total: {$this->report()['shipments']} embarques e {$this->report()['containers']} containers")
            ->emptyStateHeading(fn () => $this->problem() ?? 'Nenhum embarque encontrado para os filtros informados.')
            ->filtersLayout(FiltersLayout::AboveContent)
            ->deferFilters()
            ->filtersApplyAction(fn (Action $action) => $action->label('Filtrar'))
            ->filtersResetAction(fn (Action $action) => $action->label('Limpar'))
            ->filters([
                Filter::make('params')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 4])
                            ->schema([
                                DatePicker::make('data_ini')
                                    ->label('Data Inicial')
                                    ->required(),
                                DatePicker::make('data_fim')
                                    ->label('Data Final')
                                    ->required(),
                                Select::make('impor_expor')
                                    ->label('Tipo')
                                    ->options(['i' => 'Importação', 'e' => 'Exportação']),
                                ReportFields::person('pessoa_id', 'Cliente'),
                                ReportFields::country('pais_id', 'País'),
                                ReportFields::port('porto_origem_id', 'Porto de Origem'),
                                ReportFields::port('porto_destino_id', 'Porto de Destino'),
                                Toggle::make('embarque_confirmado')
                                    ->label('Embarques confirmados'),
                            ]),
                    ]),
            ])
            ->columns([
                TextColumn::make('country')
                    ->label('País')
                    ->sortable(),
                TextColumn::make('shipments')
                    ->label('Embarques')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('shipmentsPercent')
                    ->label('% Embarques')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2, ',', '.').'%')
                    ->sortable(),
                TextColumn::make('containers')
                    ->label('Containers')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('containersPercent')
                    ->label('% Containers')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2, ',', '.').'%')
                    ->sortable(),
            ]);
    }

    /** Por que não consultou (as duas validações do legado), ou null. */
    private function problem(): ?string
    {
        $filters = $this->tableFilters['params'] ?? [];

        if (blank($filters['data_ini'] ?? null) || blank($filters['data_fim'] ?? null)) {
            return 'Informe a Data Inicial e a Data Final para gerar o relatório.';
        }

        return $filters['data_ini'] > $filters['data_fim'] ? 'A Data Inicial não pode ser maior que a Data Final.' : null;
    }

    /** @return array{rows: list<array<string, mixed>>, shipments: int, containers: int} */
    private function report(): array
    {
        return $this->report ??= $this->problem() === null
            ? app(CountryMovementReport::class)($this->tableFilters['params'])
            : ['rows' => [], 'shipments' => 0, 'containers' => 0];
    }
}
