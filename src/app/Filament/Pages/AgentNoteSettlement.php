<?php

namespace App\Filament\Pages;

use App\Actions\AgentNotes\AgentNoteLists;
use App\Actions\AgentNotes\AgentNotePayments;
use App\Filament\Resources\VariedExpenses\VariedExpenseResource;
use App\Models\Pessoa;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
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
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * controleCreditDebitAgente/list (legado, "Baixa Notas de Crédito/Débito Agentes"): notas de crédito, débito e variadas em
 * aberto de um agente, num período opcional; "Dar baixa" grava a data de pagamento nas marcadas.
 */
class AgentNoteSettlement extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = 'Baixa de Notas de Crédito/Débito por Agente';

    protected static ?string $navigationLabel = 'Baixa Notas de Crédito/Débito Agentes';

    protected static ?string $slug = 'financeiro/baixa-notas-agentes';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?int $navigationSort = 110;

    /** security.yml do módulo (legado): credenciais manager e financial. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential(['manager', 'financial']);
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
            ->records(fn (array $filters): Collection => filled($agentId = $filters['busca']['agente_id'] ?? null)
                ? app(AgentNoteLists::class)->open((int) $agentId, $filters['busca']['data_ini'] ?? null, $filters['busca']['data_fim'] ?? null)
                : collect())
            ->paginated(false)
            ->emptyStateHeading('Nenhum registro encontrado')
            ->filtersLayout(FiltersLayout::AboveContent)
            ->deferFilters()
            ->filtersApplyAction(fn (Action $action) => $action->label('Visualizar'))
            ->filtersResetAction(fn (Action $action) => $action->label('Limpar'))
            ->filters([
                Filter::make('busca')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 3])
                            ->schema([
                                Select::make('agente_id')
                                    ->label('Agente')
                                    ->searchable()
                                    ->getSearchResultsUsing(fn (string $search) => VariedExpenseResource::agents()
                                        ->where('pessoa.nome', 'like', "%{$search}%")
                                        ->limit(30)
                                        ->pluck('pessoa.nome', 'pessoa.pessoa_id'))
                                    ->getOptionLabelUsing(fn ($value) => Pessoa::query()->whereKey($value)->value('nome')),
                                DatePicker::make('data_ini')
                                    ->label('Data Inicial'),
                                DatePicker::make('data_fim')
                                    ->label('Data Final'),
                            ]),
                    ]),
            ])
            ->columns([
                TextColumn::make('name')
                    ->label('Nome Nota'),
                TextColumn::make('created_at')
                    ->label('Data Criação')
                    ->date('d/m/Y'),
                TextColumn::make('booking')
                    ->label('Booking'),
                TextColumn::make('house_bl')
                    ->label('HBL'),
                TextColumn::make('invoice')
                    ->label('Invoice'),
                TextColumn::make('valor')
                    ->label('Valor')
                    ->formatStateUsing(fn ($state, array $record) => trim($record['symbol'].' '.number_format((float) $state, 2, ',', '.'))),
                TextColumn::make('shipper')
                    ->label('Shipper')
                    ->wrap(),
            ])
            ->toolbarActions([
                BulkAction::make('baixar')
                    ->label('Dar baixa')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->modalHeading('Data da Baixa')
                    ->modalSubmitActionLabel('Salvar')
                    ->schema([
                        DatePicker::make('data_pagamento')
                            ->label('Data Pagamento')
                            ->required(),
                    ])
                    ->action(function (Collection $records, array $data, AgentNotePayments $payments) {
                        $payments->settle($records->pluck('key')->all(), $data['data_pagamento']);

                        Notification::make()->title('Baixa realizada com sucesso')->success()->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ]);
    }
}
