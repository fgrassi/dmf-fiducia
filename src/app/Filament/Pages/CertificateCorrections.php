<?php

namespace App\Filament\Pages;

use App\Actions\Financeiro\CertificateCorrectionsReport;
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
 * relatorioDespesasReceitas (legado), "Arrecadação Certificados e Correções": despesa × receita de certificados e correções
 * por reserva e data da despesa (ETD no EXPO, ETA no IMPO). Fica no menu Relatórios.
 */
class CertificateCorrections extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = 'Arrecadação Certificados e Correções';

    protected static ?string $navigationLabel = 'Arrecadação Certificados e Correções';

    protected static ?string $slug = 'financeiro/relatorios/arrecadacao-certificados-correcoes';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationParentItem = 'Relatórios';

    protected static ?int $navigationSort = 17;

    /** Relatório da requisição: a tabela e os totais leem o mesmo resultado. */
    protected ?array $report = null;

    /** O módulo não tinha security.yml; segue a credencial dos demais relatórios financeiros. */
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
        $money = fn ($value) => number_format((float) $value, 2, ',', '.');

        return $table
            ->records(fn (int $page, int|string $recordsPerPage, ?string $sortColumn, ?string $sortDirection) => ReportRecords::paginate($this->report()['rows'], $page, $recordsPerPage, $sortColumn, $sortDirection))
            ->description(function () use ($money): string {
                $totals = $this->report()['totais'];

                return 'Despesa: R$ '.$money($totals['despesa']).' · Receita: R$ '.$money($totals['receita']).' · Resultado: R$ '.$money($totals['resultado']);
            })
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading('Nenhum registro encontrado para o período informado')
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
                TextColumn::make('data_despesa')
                    ->label('Data')
                    ->sortable()
                    ->date('d/m/Y'),
                TextColumn::make('nome_despesa')
                    ->label('Item')
                    ->sortable(),
                TextColumn::make('despesa')
                    ->label('Compra')
                    ->alignEnd()
                    ->sortable()
                    ->formatStateUsing(fn ($state, array $record) => $record['moeda_compra'].' '.$money($state)),
                TextColumn::make('receita')
                    ->label('Venda')
                    ->alignEnd()
                    ->sortable()
                    ->formatStateUsing(fn ($state, array $record) => $record['moeda_venda'].' '.$money($state)),
                TextColumn::make('despesa_rs')
                    ->label('Despesa')
                    ->alignEnd()
                    ->sortable()
                    ->formatStateUsing(fn ($state) => 'R$ '.$money($state)),
                TextColumn::make('receita_rs')
                    ->label('Receita')
                    ->alignEnd()
                    ->sortable()
                    ->formatStateUsing(fn ($state) => 'R$ '.$money($state)),
                TextColumn::make('resultado')
                    ->label('Resultado')
                    ->alignEnd()
                    ->sortable()
                    ->formatStateUsing(fn ($state) => 'R$ '.$money($state)),
            ]);
    }

    /** @return array{rows: list<array<string, mixed>>, totais: array{despesa: float, receita: float, resultado: float}} */
    private function report(): array
    {
        return $this->report ??= app(CertificateCorrectionsReport::class)($this->tableFilters['periodo'] ?? []);
    }
}
