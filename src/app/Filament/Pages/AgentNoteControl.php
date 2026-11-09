<?php

namespace App\Filament\Pages;

use App\Actions\AgentNotes\AgentNoteLists;
use App\Actions\AgentNotes\AgentNotePayments;
use App\Actions\AgentNotes\AgentNoteStatementPdf;
use App\Filament\Resources\AgentBankAccounts\AgentBankAccountResource;
use App\Filament\Resources\VariedExpenses\VariedExpenseResource;
use App\Models\AgenteBanco;
use App\Models\Pessoa;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
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
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Notas de crédito, débito e variadas de um agente numa tela só, no lugar de três telas do legado:
 * - "Em aberto" (controleCreditDebitAgente/list, "Baixa Notas de Crédito/Débito Agentes"): período da data de criação
 *   opcional; "Dar baixa" grava a data de pagamento nas marcadas;
 * - "Com baixa" (controleNotas/list, "Remover Baixa de Notas", e controleCreditDebitAgente/consulta, "Consultas Notas
 *   Agentes"): período da data de pagamento, um lado pode ficar aberto; "Remover baixa" reabre as marcadas e "Gerar PDF"
 *   baixa o extrato do agente com a conta bancária escolhida.
 * Um item só no menu ("Notas de Crédito/Débito Agente", nome pedido pelo financeiro no lugar dos três do legado).
 *  a lista "Com baixa" usa os valores do Remover Baixa (toda nota baixada pode ser reaberta); o extrato em PDF
 * segue as regras da D13 (AgentNoteLists::statement) e deixa de fora courier de exportação e variada sem shipper.
 */
class AgentNoteControl extends Page implements HasTable
{
    use InteractsWithTable;

    private const PAGE_SLUG = 'financeiro/notas-agentes';

    protected static ?string $navigationLabel = 'Notas de Crédito/Débito Agente';

    protected static ?string $slug = self::PAGE_SLUG;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?int $navigationSort = 110;

    /** "open" (em aberto) ou "paid" (com baixa). */
    #[Url]
    public string $mode = 'open';

    /** security.yml do módulo (legado): credenciais manager e financial. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential(['manager', 'financial']);
    }

    public function getTitle(): string
    {
        return $this->isPaid() ? 'Notas de Crédito/Débito por Agente Com Baixa' : 'Baixa de Notas de Crédito/Débito por Agente';
    }

    private function isPaid(): bool
    {
        return $this->mode === 'paid';
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Actions::make([
                    Action::make('open')
                        ->label('Em aberto')
                        ->color(fn () => $this->isPaid() ? 'gray' : 'primary')
                        ->action(fn () => $this->switchMode('open')),
                    Action::make('paid')
                        ->label('Com baixa')
                        ->color(fn () => $this->isPaid() ? 'primary' : 'gray')
                        ->action(fn () => $this->switchMode('paid')),
                ])->alignCenter(),
                EmbeddedTable::make(),
            ]);
    }

    /** @return array<string, mixed> */
    private function search(): array
    {
        return $this->getTableFilterState('busca') ?? [];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (array $filters, AgentNoteLists $lists): Collection {
                if (blank($agentId = $filters['busca']['agente_id'] ?? null)) {
                    return collect();
                }

                $from = $filters['busca']['data_ini'] ?? null;
                $to = $filters['busca']['data_fim'] ?? null;

                return $this->isPaid() ? $lists->paid((int) $agentId, $from, $to) : $lists->open((int) $agentId, $from, $to);
            })
            ->paginated(false)
            ->emptyStateHeading('Nenhum registro encontrado')
            ->emptyStateDescription('Escolha o agente e clique em Visualizar.')
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
                                    ->label('Data Inicial')
                                    ->helperText(fn () => $this->isPaid() ? 'Data de pagamento.' : 'Data de criação.'),
                                DatePicker::make('data_fim')
                                    ->label('Data Final')
                                    ->helperText(fn () => $this->isPaid() ? 'Data de pagamento.' : 'Data de criação.'),
                            ]),
                    ]),
            ])
            ->headerActions([
                Action::make('pdf')
                    ->label('Gerar PDF')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->visible(fn () => $this->isPaid() && filled($this->search()['agente_id'] ?? null))
                    ->modalHeading('Dados bancários do Agente')
                    ->modalSubmitActionLabel('Gerar PDF')
                    ->schema([
                        Radio::make('conta')
                            ->label('Conta do agente')
                            ->required()
                            ->validationMessages(['required' => 'Nenhuma conta selecionada! Selecione a conta deste agente ou cadastre uma nova.'])
                            ->options(fn () => $this->accounts())
                            ->helperText(fn () => new HtmlString('Contas cadastradas em <a class="underline" href="'.e(AgentBankAccountResource::getUrl()).'">Contas Bancárias dos Agentes</a>.')),
                    ])
                    ->action(function (array $data): StreamedResponse {
                        $search = $this->search();

                        return app(AgentNoteStatementPdf::class)((int) $search['agente_id'], $search['data_ini'] ?? null, $search['data_fim'] ?? null, (int) $data['conta']);
                    }),
            ])
            ->columns([
                TextColumn::make('name')
                    ->label('Nome Nota'),
                TextColumn::make('created_at')
                    ->label('Data Criação')
                    ->date('d/m/Y'),
                TextColumn::make('data_pagamento')
                    ->label('Data Pagamento')
                    ->date('d/m/Y')
                    ->placeholder('-')
                    ->visible(fn () => $this->isPaid()),
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
                    ->visible(fn () => ! $this->isPaid())
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
                BulkAction::make('remover')
                    ->label('Remover baixa')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('danger')
                    ->visible(fn () => $this->isPaid())
                    ->requiresConfirmation()
                    ->modalHeading('Remover a baixa das notas marcadas?')
                    ->action(function (Collection $records, AgentNotePayments $payments) {
                        $payments->reopen($records->pluck('key')->all());

                        Notification::make()->title('Baixa removida com sucesso')->success()->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ]);
    }

    public function switchMode(string $mode): void
    {
        $this->mode = $mode;
        $this->deselectAllTableRecords();
        $this->flushCachedTableRecords();
    }

    /** @return array<int, string> */
    private function accounts(): array
    {
        return AgenteBanco::query()
            ->where('agente_id', $this->search()['agente_id'] ?? 0)
            ->orderBy('agente_banco_id')
            ->get()
            ->mapWithKeys(fn (AgenteBanco $account) => [$account->agente_banco_id => collect([$account->nome_banco, $account->nome_conta, $account->numero_conta])->filter()->implode(' · ')])
            ->all();
    }
}
