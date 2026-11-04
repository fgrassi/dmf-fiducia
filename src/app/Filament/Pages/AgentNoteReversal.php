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
 * controleNotas/list (legado, "Remover Baixa de Notas"): notas baixadas de um agente com data de pagamento no período
 * (agente e as duas datas obrigatórios); "Remover baixa" reabre as marcadas.
 */
class AgentNoteReversal extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = 'Remover Baixas de Notas de Crédito/Débito';

    protected static ?string $navigationLabel = 'Remover Baixa de Notas';

    protected static ?string $slug = 'financeiro/remover-baixa-notas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?int $navigationSort = 111;

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
                ? app(AgentNoteLists::class)->paid((int) $agentId, $filters['busca']['data_ini'] ?? null, $filters['busca']['data_fim'] ?? null)
                : collect())
            ->paginated(false)
            ->emptyStateHeading('Nenhum registro encontrado')
            ->emptyStateDescription('Informe o agente e as duas datas (período da data de pagamento).')
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
                TextColumn::make('data_pagamento')
                    ->label('Data Pagamento')
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
                BulkAction::make('remover')
                    ->label('Remover baixa')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Remover a baixa das notas marcadas?')
                    ->action(function (Collection $records, AgentNotePayments $payments) {
                        $payments->reopen($records->pluck('key')->all());

                        Notification::make()->title('Baixa removida com sucesso')->success()->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ]);
    }
}
