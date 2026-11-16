<?php

namespace App\Filament\Pages;

use App\Actions\Reports\LocalChargesReport;
use App\Enums\OperationType;
use App\Filament\Support\ReportFields;
use App\Filament\Support\ReportRecords;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
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

/** relTaxasLocais (legado), "Taxas Locais: Armador - Porto": despesas da matriz por armador e porto. */
class LocalCharges extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = 'Taxas Locais: Armador - Porto';

    protected static ?string $navigationLabel = 'Taxas Locais';

    protected static ?string $slug = 'relatorios/taxas-locais';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static string|UnitEnum|null $navigationGroup = 'Relatórios';

    protected static ?int $navigationSort = 50;

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
            ->records(fn (int $page, int|string $recordsPerPage, ?string $sortColumn, ?string $sortDirection) => ReportRecords::paginate($this->rows(), $page, $recordsPerPage, $sortColumn, $sortDirection))
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading('Nenhuma taxa local encontrada')
            ->filtersLayout(FiltersLayout::AboveContent)
            ->deferFilters()
            ->filtersApplyAction(fn (Action $action) => $action->label('Buscar'))
            ->filtersResetAction(fn (Action $action) => $action->label('Limpar'))
            ->filters([
                Filter::make('busca')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 3])
                            ->schema([
                                Select::make('type')
                                    ->label('Tipo')
                                    ->options(OperationType::class),
                                ReportFields::carrier('armador'),
                                ReportFields::port('porto', 'Porto'),
                            ]),
                    ]),
            ])
            ->columns([
                TextColumn::make('carrier')
                    ->label('Armador')
                    ->sortable(),
                TextColumn::make('port')
                    ->label('Porto')
                    ->sortable(),
                TextColumn::make('expense')
                    ->label('Despesa')
                    ->sortable(),
                TextColumn::make('equipment')
                    ->label('Equipamento')
                    ->sortable(),
                TextColumn::make('value')
                    ->label('Valor')
                    ->alignEnd()
                    ->sortable()
                    ->formatStateUsing(fn ($state, array $record) => trim($record['symbol'].' '.number_format((float) $state, 2, ',', '.'))),
            ]);
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        $filters = $this->tableFilters['busca'] ?? [];
        $groups = app(LocalChargesReport::class)($filters['type'] ?? null, ($filters['armador'] ?? null) ? (int) $filters['armador'] : null, ($filters['porto'] ?? null) ? (int) $filters['porto'] : null);

        return collect($groups)
            ->flatMap(fn (array $carrier) => collect($carrier['ports'])->flatMap(fn (array $port) => array_map(fn (array $charge) => ['carrier' => $carrier['carrier'], 'port' => $port['port']] + $charge, $port['charges'])))
            ->all();
    }
}
