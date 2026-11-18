<?php

namespace App\Filament\Pages;

use App\Actions\Reports\MailCostsPdf;
use App\Actions\Reports\MailCostsReport;
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
 * relCustosCorreio (legado), "Custos Correio": envios por Sedex do período, vinculados a reservas (valor rateado entre elas) ou de
 * formulário, com os totais e o PDF. Fica no menu Relatórios.
 */
class MailCosts extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = 'Custos Correio';

    protected static ?string $navigationLabel = 'Custos Correio';

    protected static ?string $slug = 'relatorios/custos-correio';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static string|UnitEnum|null $navigationGroup = 'Relatórios';

    protected static ?int $navigationSort = 70;

    /** Relatório da requisição: a tabela e o total leem o mesmo resultado. */
    protected ?array $report = null;

    /** security.yml do módulo (legado): credencial auxiliares. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('auxiliares');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pdf')
                ->label('Gerar PDF')
                ->color('gray')
                ->action(fn () => app(MailCostsPdf::class)($this->period())),
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
            ->description(fn () => 'Vinculados a reservas: R$ '.$this->money($this->report()['linked_total'])
                .' · Não vinculados (formulário): R$ '.$this->money($this->report()['form_total'])
                .' · Total Correios: R$ '.$this->money($this->report()['total']))
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading('Nenhum registro encontrado')
            ->filtersLayout(FiltersLayout::AboveContent)
            ->deferFilters()
            ->filtersApplyAction(fn (Action $action) => $action->label('Gerar relatório'))
            ->filtersResetAction(fn (Action $action) => $action->label('Limpar'))
            ->filters([
                Filter::make('periodo')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 2])
                            ->schema([
                                DatePicker::make('data_ini')
                                    ->label('Período, de')
                                    ->default(today()),
                                DatePicker::make('data_fim')
                                    ->label('até')
                                    ->default(today()),
                            ]),
                    ]),
            ])
            ->columns([
                TextColumn::make('num_sedex')
                    ->label('Número Correio')
                    ->sortable(),
                TextColumn::make('data_envio')
                    ->label('Data de envio')
                    ->sortable()
                    ->date('d/m/Y'),
                TextColumn::make('bookings')
                    ->label('Reserva')
                    ->state(fn (array $record) => array_column($record['bookings'], 'name'))
                    ->listWithLineBreaks()
                    ->placeholder('-'),
                TextColumn::make('value_per_booking')
                    ->label('Valor/Reserva')
                    ->state(fn (array $record) => array_fill(0, count($record['bookings']), 'R$ '.$this->money($record['value_per_booking'])))
                    ->listWithLineBreaks()
                    ->placeholder('-'),
                TextColumn::make('destinatario')
                    ->label('Descrição (formulário)')
                    ->state(fn (array $record) => $record['is_form'] ? $record['destinatario'] : null)
                    ->wrap()
                    ->placeholder('-'),
                TextColumn::make('valor')
                    ->label('Valor Total')
                    ->alignEnd()
                    ->sortable()
                    ->formatStateUsing(fn ($state) => 'R$ '.$this->money($state)),
            ]);
    }

    /** @return array{rows: list<array<string, mixed>>, linked_total: float, form_total: float, total: float} */
    private function report(): array
    {
        return $this->report ??= app(MailCostsReport::class)($this->period());
    }

    /** @return array{data_ini?: ?string, data_fim?: ?string} */
    private function period(): array
    {
        return $this->tableFilters['periodo'] ?? [];
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, ',', '.');
    }
}
