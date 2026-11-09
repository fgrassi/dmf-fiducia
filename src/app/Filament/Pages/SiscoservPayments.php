<?php

namespace App\Filament\Pages;

use App\Actions\Financeiro\SiscoservPaymentsReport;
use App\Filament\Support\ReportRecords;
use App\Models\Pessoa;
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

/**
 * pagamentoSiscoserv/index (legado), "Pagamentos Siscoserv" (menu 6004), no menu Consultas: reservas marítimas de importação
 * com cobrança paga no período, por cliente. O legado não tinha security.yml neste módulo: segue a credencial financial.
 * Sem nenhum filtro não lista nada, como o legado.
 */
class SiscoservPayments extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = 'Pagamentos Siscoserv';

    protected static ?string $navigationLabel = 'Pagamentos Siscoserv';

    protected static ?string $slug = 'financeiro/pagamentos-siscoserv';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationParentItem = 'Consultas';

    protected static ?int $navigationSort = 40;

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
                app(SiscoservPaymentsReport::class)($this->tableFilters['busca'] ?? [])->map(fn (object $row) => (array) $row)->all(),
                $page,
                $recordsPerPage,
                $sortColumn,
                $sortDirection,
            ))
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading('Nenhum pagamento encontrado')
            ->filtersLayout(FiltersLayout::AboveContent)
            ->deferFilters()
            ->filtersApplyAction(fn (Action $action) => $action->label('Filtrar'))
            ->filtersResetAction(fn (Action $action) => $action->label('Limpar'))
            ->filters([
                Filter::make('busca')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 3])
                            ->schema([
                                DatePicker::make('data_ini')
                                    ->label('Data inicial'),
                                DatePicker::make('data_fim')
                                    ->label('Data final'),
                                Select::make('cliente_id')
                                    ->label('Cliente')
                                    ->searchable()
                                    ->getSearchResultsUsing(fn (string $search) => Pessoa::query()
                                        ->where(fn ($query) => $query->where('nome', 'like', "%{$search}%")->orWhere('cnpj', 'like', "%{$search}%"))
                                        ->orderBy('nome')
                                        ->limit(30)
                                        ->get()
                                        ->mapWithKeys(fn (Pessoa $person) => [$person->getKey() => $person->nome.(filled($person->cnpj) ? " ({$person->cnpj})" : '')]))
                                    ->getOptionLabelUsing(fn ($value) => Pessoa::query()->whereKey($value)->value('nome')),
                            ]),
                    ]),
            ])
            ->columns([
                TextColumn::make('cliente')
                    ->label('Cliente')
                    ->sortable(),
                TextColumn::make('agente')
                    ->label('Agente')
                    ->sortable(),
                TextColumn::make('reserva')
                    ->label('Reserva')
                    ->sortable(),
                TextColumn::make('escritorio')
                    ->label('Escritório')
                    ->sortable(),
            ]);
    }
}
