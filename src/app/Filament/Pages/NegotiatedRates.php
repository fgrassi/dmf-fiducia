<?php

namespace App\Filament\Pages;

use App\Actions\Reports\NegotiatedRatesReport;
use App\Enums\OperationType;
use App\Filament\Support\ReportRecords;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
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

/** relTaxasNegociadas (legado), "Taxas Negociadas": cotações de moeda negociadas à mão nas cobranças do período. */
class NegotiatedRates extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = 'Taxas Negociadas';

    protected static ?string $navigationLabel = 'Taxas Negociadas';

    protected static ?string $slug = 'relatorios/taxas-negociadas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Relatórios';

    protected static ?int $navigationSort = 30;

    /** security.yml do módulo (legado): credencial manager. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('manager');
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
            ->records(function (int $page, int|string $recordsPerPage, ?string $sortColumn, ?string $sortDirection) {
                $filters = $this->tableFilters['periodo'] ?? [];

                return ReportRecords::paginate(app(NegotiatedRatesReport::class)($filters['data_ini'] ?? null, $filters['data_fim'] ?? null, $filters['impor_expor'] ?? null), $page, $recordsPerPage, $sortColumn, $sortDirection);
            })
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading('Nenhuma taxa negociada no período informado')
            ->filtersLayout(FiltersLayout::AboveContent)
            ->deferFilters()
            ->filtersApplyAction(fn (Action $action) => $action->label('Filtrar'))
            ->filtersResetAction(fn (Action $action) => $action->label('Limpar'))
            ->filters([
                Filter::make('periodo')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 3])
                            ->schema([
                                DatePicker::make('data_ini')
                                    ->label('Data inicial')
                                    ->default(now()->subMonth()),
                                DatePicker::make('data_fim')
                                    ->label('Data final')
                                    ->default(now()),
                                Select::make('impor_expor')
                                    ->label('Tipo de embarque')
                                    ->options(OperationType::class),
                            ]),
                    ]),
            ])
            ->columns([
                TextColumn::make('date')
                    ->label('Data')
                    ->sortable()
                    ->date('d/m/Y'),
                TextColumn::make('operation')
                    ->label('Tipo embarque')
                    ->sortable(),
                TextColumn::make('booking')
                    ->label('Booking')
                    ->sortable(),
                TextColumn::make('total')
                    ->label('Total cobrança')
                    ->alignEnd()
                    ->sortable()
                    ->formatStateUsing(fn ($state) => 'R$ '.number_format((float) $state, 2, ',', '.')),
                TextColumn::make('client')
                    ->label('Cliente')
                    ->wrap()
                    ->sortable(),
                TextColumn::make('currency')
                    ->label('Moeda'),
                TextColumn::make('rate')
                    ->label('Valor negociado')
                    ->alignEnd()
                    ->sortable()
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2, ',', '.')),
                TextColumn::make('author')
                    ->label('Cadastrado por')
                    ->wrap(),
                TextColumn::make('note')
                    ->label('Observações')
                    ->wrap(),
            ]);
    }
}
