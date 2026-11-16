<?php

namespace App\Filament\Pages;

use App\Actions\Reports\ExportSpreadsheet;
use App\Actions\Reports\TransitTimeByPortReport;
use App\Filament\Support\ReportRecords;
use App\Filament\Support\TransitTimeColumns;
use App\Models\Porto;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use UnitEnum;

/** relTransitTime/transitTimePorto (legado), "Transit Time - Destino": por armador, o transit time de uma origem e/ou destino. */
class TransitTimeByPort extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = 'Transit Time - Destino';

    protected static ?string $navigationLabel = 'Transit Time - Destino';

    protected static ?string $slug = 'relatorios/transit-time-destino';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|UnitEnum|null $navigationGroup = 'Relatórios';

    protected static ?int $navigationSort = 41;

    /** Relatório da requisição: a tabela e a exportação leem o mesmo resultado. */
    protected ?array $report = null;

    /** security.yml do módulo (legado): credencial relatorio. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('relatorio');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Exportar XLS')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->visible(fn () => $this->report() !== [])
                ->action(fn () => app(ExportSpreadsheet::class)('Transit Time - Destino', TransitTimeColumns::HEADERS, TransitTimeColumns::sheetRows($this->report()))),
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
            ->records(fn (int $page, int|string $recordsPerPage, ?string $sortColumn, ?string $sortDirection) => ReportRecords::paginate($this->report(), $page, $recordsPerPage, $sortColumn, $sortDirection))
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading(fn () => ($this->tableFilters['trecho']['origem'] ?? null) || ($this->tableFilters['trecho']['destino'] ?? null) ? 'Nenhuma reserva para o trecho informado' : 'Informe a origem e/ou o destino')
            ->filtersLayout(FiltersLayout::AboveContent)
            ->deferFilters()
            ->filtersApplyAction(fn (Action $action) => $action->label('Enviar'))
            ->filtersResetAction(fn (Action $action) => $action->label('Limpar'))
            ->filters([
                Filter::make('trecho')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 2])
                            ->schema([
                                Select::make('origem')
                                    ->label('Porto de origem')
                                    ->searchable()
                                    ->getSearchResultsUsing(fn (string $search) => Porto::query()->where('nome', 'like', "%{$search}%")->orderBy('nome')->limit(30)->pluck('nome', 'porto_id'))
                                    ->getOptionLabelUsing(fn ($value) => Porto::query()->whereKey($value)->value('nome')),
                                Select::make('destino')
                                    ->label('Porto de destino')
                                    ->searchable()
                                    ->getSearchResultsUsing(fn (string $search) => Porto::query()->where('nome', 'like', "%{$search}%")->orderBy('nome')->limit(30)->pluck('nome', 'porto_id'))
                                    ->getOptionLabelUsing(fn ($value) => Porto::query()->whereKey($value)->value('nome')),
                            ]),
                    ]),
            ])
            ->columns(TransitTimeColumns::make('TT Estimado (média)', 'POO', 'POD'));
    }

    /** @return list<array<string, mixed>> */
    private function report(): array
    {
        $filters = $this->tableFilters['trecho'] ?? [];

        return $this->report ??= app(TransitTimeByPortReport::class)(($filters['origem'] ?? null) ? (int) $filters['origem'] : null, ($filters['destino'] ?? null) ? (int) $filters['destino'] : null);
    }
}
