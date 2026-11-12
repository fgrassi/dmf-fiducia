<?php

namespace App\Filament\Pages;

use App\Actions\AgentNotes\AgentNoteLists;
use App\Filament\Resources\VariedExpenses\VariedExpenseResource;
use App\Filament\Support\OperationBadge;
use App\Models\Pessoa;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\ArrayRecord;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use UnitEnum;

/**
 * consultaNotas/index (legado, "Consulta Notas Debit/Credit"): notas de crédito, débito e variadas de todos os agentes,
 * abertas ou baixadas, com filtros de agente, período de criação e de pagamento, tipo, status, booking, HBL, invoice e
 * shipper (busca parcial). Sem filtro são mais de 34 mil linhas, por isso a lista é paginada.
 */
class AgentNoteSearch extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = 'Consulta a Notas de Agentes';

    protected static ?string $navigationLabel = 'Consulta Notas Debit/Credit';

    protected static ?string $slug = 'financeiro/consulta-notas-debit-credit';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationParentItem = 'Consultas';

    protected static ?int $navigationSort = 20;

    /** security.yml do módulo (legado): só a credencial financial. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential(['financial']);
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
            ->records(fn (array $filters, int $page, int|string $recordsPerPage): LengthAwarePaginator => app(AgentNoteLists::class)
                ->search($filters['busca'] ?? [], $page, (int) $recordsPerPage)
                ->through(fn (object $row) => (array) $row + [ArrayRecord::getKeyName() => "{$row->tipo}_{$row->id}"]))
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading('Nenhum registro encontrado')
            ->filtersLayout(FiltersLayout::AboveContent)
            ->deferFilters()
            ->filtersApplyAction(fn (Action $action) => $action->label('Enviar'))
            ->filtersResetAction(fn (Action $action) => $action->label('Limpar'))
            ->filters([
                Filter::make('busca')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 4])
                            ->schema([
                                Select::make('agente_id')
                                    ->label('Agente')
                                    ->searchable()
                                    ->columnSpan(['md' => 2])
                                    ->getSearchResultsUsing(fn (string $search) => VariedExpenseResource::agents()
                                        ->where('pessoa.nome', 'like', "%{$search}%")
                                        ->limit(30)
                                        ->pluck('pessoa.nome', 'pessoa.pessoa_id'))
                                    ->getOptionLabelUsing(fn ($value) => Pessoa::query()->whereKey($value)->value('nome')),
                                Select::make('tipo')
                                    ->label('Tipo')
                                    ->options(['c' => 'Crédito', 'd' => 'Débito']),
                                Select::make('status')
                                    ->label('Status')
                                    ->options(['0' => 'Aberto', '1' => 'Com Baixa']),
                                DatePicker::make('data_ini')
                                    ->label('Data Inicial'),
                                DatePicker::make('data_fim')
                                    ->label('Data Final'),
                                DatePicker::make('pagamento_ini')
                                    ->label('Pagamento Inicial'),
                                DatePicker::make('pagamento_fim')
                                    ->label('Pagamento Final'),
                                TextInput::make('booking')
                                    ->label('Booking'),
                                TextInput::make('hbl')
                                    ->label('HBL'),
                                TextInput::make('invoice')
                                    ->label('Invoice'),
                                TextInput::make('shipper')
                                    ->label('Shipper'),
                            ]),
                    ]),
            ])
            ->columns([
                TextColumn::make('name')
                    ->label('Nota'),
                TextColumn::make('agente')
                    ->label('Agente')
                    ->wrap(),
                TextColumn::make('created_at')
                    ->label('Data')
                    ->date('d/m/Y'),
                OperationBadge::column('tipo_embarque')
                    ->label('Tipo Embarque'),
                TextColumn::make('tipo')
                    ->label('Tipo Nota')
                    ->state(fn (array $record) => ($record['tipo'] === 'c' || $record['tipo_nota'] == 1 && $record['tipo'] === 'v') ? 'Crédito' : (($record['tipo'] === 'd' || $record['tipo_nota'] == 2) ? 'Débito' : '')),
                TextColumn::make('booking')
                    ->label('Booking'),
                TextColumn::make('house_bl')
                    ->label('HBL'),
                TextColumn::make('invoice')
                    ->label('Invoice'),
                TextColumn::make('valor')
                    ->label('Valor')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state, array $record) => trim($record['symbol'].' '.number_format((float) $state, 2, ',', '.'))),
                TextColumn::make('shipper')
                    ->label('Shipper')
                    ->wrap(),
                TextColumn::make('status_pagamento')
                    ->label('Status Baixa')
                    ->formatStateUsing(fn ($state) => (int) $state === 1 ? 'Baixada' : 'Aberto'),
                TextColumn::make('data_pagamento')
                    ->label('Data Pagamento')
                    ->date('d/m/Y'),
            ]);
    }
}
