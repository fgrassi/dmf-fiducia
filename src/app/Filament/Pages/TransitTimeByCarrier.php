<?php

namespace App\Filament\Pages;

use App\Actions\Reports\ExportSpreadsheet;
use App\Actions\Reports\TransitTimeByCarrierReport;
use App\Filament\Support\ReportRecords;
use App\Filament\Support\TransitTimeColumns;
use App\Models\Armador;
use App\Models\Pais;
use App\Models\Porto;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
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

/** relTransitTime/transitTimeArmador (legado), "Transit Time - Armador": médias de transit time por armador e trecho. */
class TransitTimeByCarrier extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = 'Transit Time - Armador';

    protected static ?string $navigationLabel = 'Transit Time - Armador';

    protected static ?string $slug = 'relatorios/transit-time-armador';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Relatórios';

    protected static ?int $navigationSort = 40;

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
                ->action(fn () => app(ExportSpreadsheet::class)('Transit Time - Armador', TransitTimeColumns::HEADERS, TransitTimeColumns::sheetRows($this->report()))),
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
            ->emptyStateHeading(fn () => blank($this->filters()['data_ini'] ?? null) ? 'Informe a data inicial da saída efetiva' : 'Nenhuma reserva no período informado')
            ->filtersLayout(FiltersLayout::AboveContent)
            ->deferFilters()
            ->filtersApplyAction(fn (Action $action) => $action->label('Enviar'))
            ->filtersResetAction(fn (Action $action) => $action->label('Limpar'))
            ->filters([
                Filter::make('periodo')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 3])
                            ->schema([
                                DatePicker::make('data_ini')
                                    ->label('Saída efetiva de')
                                    ->required(),
                                DatePicker::make('data_fim')
                                    ->label('Saída efetiva até'),
                                Select::make('tipo')
                                    ->label('Tipo de embarque')
                                    ->options(['m' => 'Marítimo', 'a' => 'Aéreo']),
                                Select::make('armador')
                                    ->label('Armador')
                                    ->searchable()
                                    ->options(fn () => Armador::query()->orderBy('nome')->pluck('nome', 'armador_id')),
                                Select::make('origem')
                                    ->label('POL')
                                    ->searchable()
                                    ->getSearchResultsUsing(fn (string $search) => Porto::query()->where('nome', 'like', "%{$search}%")->orderBy('nome')->limit(30)->pluck('nome', 'porto_id'))
                                    ->getOptionLabelUsing(fn ($value) => Porto::query()->whereKey($value)->value('nome')),
                                Select::make('destino')
                                    ->label('POD')
                                    ->searchable()
                                    ->getSearchResultsUsing(fn (string $search) => Porto::query()->where('nome', 'like', "%{$search}%")->orderBy('nome')->limit(30)->pluck('nome', 'porto_id'))
                                    ->getOptionLabelUsing(fn ($value) => Porto::query()->whereKey($value)->value('nome')),
                                Select::make('paises_origem')
                                    ->label('Países origem')
                                    ->multiple()
                                    ->searchable()
                                    ->options(fn () => Pais::query()->orderBy('nome')->pluck('nome', 'paises_id')),
                                Select::make('paises_destino')
                                    ->label('Países destino')
                                    ->multiple()
                                    ->searchable()
                                    ->options(fn () => Pais::query()->orderBy('nome')->pluck('nome', 'paises_id')),
                                Checkbox::make('ocultar_qtd')
                                    ->label('Ocultar quantidade de embarques'),
                            ]),
                    ]),
            ])
            ->columns(TransitTimeColumns::make('TT Inicial (média)', 'POL', 'POD', fn () => ! ($this->filters()['ocultar_qtd'] ?? false)));
    }

    /** @return array<string, mixed> */
    private function filters(): array
    {
        return $this->tableFilters['periodo'] ?? [];
    }

    /** @return list<array<string, mixed>> */
    private function report(): array
    {
        $filters = $this->filters();

        if (blank($filters['data_ini'] ?? null)) {
            return [];
        }

        return $this->report ??= app(TransitTimeByCarrierReport::class)(
            $filters['data_ini'],
            $filters['data_fim'] ?? null,
            $filters['tipo'] ?? null,
            ($filters['armador'] ?? null) ? (int) $filters['armador'] : null,
            ($filters['origem'] ?? null) ? (int) $filters['origem'] : null,
            ($filters['destino'] ?? null) ? (int) $filters['destino'] : null,
            array_map('intval', $filters['paises_origem'] ?? []),
            array_map('intval', $filters['paises_destino'] ?? []),
        );
    }
}
