<?php

namespace App\Filament\Pages;

use App\Actions\AgentNotes\AgentPendingStatement;
use App\Actions\AgentNotes\AgentPendingSummary;
use App\Actions\AgentNotes\SendAgentPendingStatement;
use App\Actions\AgentNotes\SyncAgentObservations;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * resumoNotasAgentes/index (legado), "Relatório Pendências Agente" (menu 3342): o que cada agente deve à DMF e o que a DMF deve a
 * ele (AgentPendingSummary), com a data limite de câmbio editável, o histórico de observações, o extrato em Excel e o envio dele
 * por e-mail. Credenciais do security.yml do módulo: manager e financial.
 */
class AgentPendingReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = 'Relatório de Pendências Por Agente';

    protected static ?string $navigationLabel = 'Relatório Pendências Agente';

    protected static ?string $slug = 'financeiro/pendencias-agente';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationParentItem = 'Relatórios';

    protected static ?int $navigationSort = 21;

    /** Falso: agentes sem restrição e com pendência. Verdadeiro: agentes com restrição, todos (o "tudo=1" do legado). */
    #[Url]
    public bool $all = false;

    private ?Collection $summary = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential(['manager', 'financial']);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function summary(): Collection
    {
        return $this->summary ??= app(AgentPendingSummary::class)($this->all);
    }

    private function money(float $value): string
    {
        return 'USD '.number_format($value, 2, ',', '.');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): Collection => $this->summary()->keyBy('pessoa_id'))
            ->paginated(false)
            ->description(fn () => 'Total '.$this->money($this->summary()->sum('total')))
            ->emptyStateHeading('Nenhum agente encontrado')
            ->headerActions([
                Action::make('toggle')
                    ->label(fn () => $this->all ? 'Mostrar Agentes com Pendência de Pagamento e Sem Bloqueio' : 'Mostrar Agentes sem Pendência de Pagamento ou Com Bloqueio')
                    ->color('gray')
                    ->action(function () {
                        $this->all = ! $this->all;
                        $this->summary = null;
                        $this->flushCachedTableRecords();
                    }),
            ])
            ->columns([
                TextColumn::make('agente')
                    ->label('Agente'),
                TextColumn::make('data_limite_cambio')
                    ->label('Data Limite Câmbio')
                    ->date('d/m/Y')
                    ->placeholder('definir')
                    ->action($this->limitAction()),
                TextColumn::make('due_to_agente')
                    ->label('Due to Agente')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state) => $this->money((float) $state)),
                TextColumn::make('due_to_dmf')
                    ->label('Due to DMF')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state) => $this->money((float) $state)),
                TextColumn::make('total')
                    ->label('Total')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state) => $this->money((float) $state)),
                TextColumn::make('data_obs')
                    ->label('Último Histórico')
                    ->date('d/m/Y'),
            ])
            ->recordActions([
                $this->historyAction(),
                ...$this->statementActions(),
            ]);
    }

    private function limitAction(): Action
    {
        return Action::make('limit')
            ->modalHeading('Data Limite Câmbio')
            ->modalSubmitActionLabel('Salvar')
            ->fillForm(fn (array $record) => ['data_limite_cambio' => $record['data_limite_cambio']])
            ->schema([
                DatePicker::make('data_limite_cambio')
                    ->label('Data Limite Câmbio'),
            ])
            ->action(function (array $record, array $data) {
                DB::table('pessoa')->where('pessoa_id', $record['pessoa_id'])->update(['data_limite_cambio' => $data['data_limite_cambio'] ?: null]);

                $this->summary = null;
            });
    }

    private function historyAction(): Action
    {
        return Action::make('history')
            ->label('Histórico')
            ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
            ->modalHeading(fn (array $record) => 'Histórico para '.$record['agente'])
            ->modalSubmitActionLabel('Salvar')
            ->fillForm(fn (array $record) => ['items' => DB::table('agente_observacao')
                ->where('agente_id', $record['pessoa_id'])
                ->orderByDesc('data_observacao')
                ->orderByDesc('agente_observacao_id')
                ->get(['agente_observacao_id as id', 'data_observacao', 'observacao'])
                ->map(fn (object $item) => (array) $item)
                ->all()])
            ->schema([
                Repeater::make('items')
                    ->label('Observações')
                    ->addActionLabel('Incluir novo histórico')
                    ->defaultItems(0)
                    ->schema([
                        Hidden::make('id'),
                        DatePicker::make('data_observacao')
                            ->label('Data')
                            ->default(now())
                            ->required()
                            ->disabled(fn (callable $get) => filled($get('id'))),
                        TextInput::make('observacao')
                            ->label('Observação')
                            ->required()
                            ->disabled(fn (callable $get) => filled($get('id'))),
                    ])
                    ->columns(2),
            ])
            ->action(function (array $record, array $data, SyncAgentObservations $sync) {
                $sync($record['pessoa_id'], array_values($data['items'] ?? []), (int) auth()->id());
                $this->summary = null;

                Notification::make()->title('Histórico salvo')->success()->send();
            });
    }

    /** O legado tem um Excel e um e-mail por agente; nos agentes AIC são dois de cada, marítimo (SEA) e aéreo (AIR). @return list<Action> */
    private function statementActions(): array
    {
        $types = ['t' => '', 'm' => ' (SEA)', 'a' => ' (AIR)'];
        $visible = fn (string $type): Closure => fn (array $record) => $type === 't' ? ! AgentPendingStatement::isAic($record['pessoa_id']) : AgentPendingStatement::isAic($record['pessoa_id']);
        $actions = [];

        foreach ($types as $type => $suffix) {
            $actions[] = Action::make("xls_{$type}")
                ->label("Baixar Excel{$suffix}")
                ->icon(Heroicon::OutlinedTableCells)
                ->visible($visible($type))
                ->action(function (array $record, AgentPendingStatement $statement) use ($type) {
                    $content = $statement($record['pessoa_id'], $type);

                    return response()->streamDownload(fn () => print $content, $statement->fileName(), ['Content-Type' => 'application/vnd.ms-excel']);
                });
            $actions[] = Action::make("email_{$type}")
                ->label("Enviar E-mail{$suffix}")
                ->icon(Heroicon::OutlinedEnvelope)
                ->visible($visible($type))
                ->modalHeading('Tem certeza?')
                ->modalDescription('A mensagem será enviada para o setor financeiro e todos os contatos informados.')
                ->modalSubmitActionLabel('Sim')
                ->fillForm(fn (array $record) => ['contatos' => $record['contato']])
                ->schema([
                    Textarea::make('contatos')
                        ->label('E-mails do agente')
                        ->helperText('Separe os endereços por vírgula.')
                        ->rule(fn () => function (string $attribute, $value, Closure $fail) {
                            $invalid = collect(SendAgentPendingStatement::parseContacts($value))->reject(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL));

                            if ($invalid->isNotEmpty()) {
                                $fail('E-mail inválido: '.$invalid->implode(', '));
                            }
                        }),
                ])
                ->action(function (array $record, array $data, SendAgentPendingStatement $send) use ($type) {
                    $send($record['pessoa_id'], $type, $data['contatos'] ?? null);
                    $this->summary = null;

                    Notification::make()->title('E-mail enviado')->success()->send();
                });
        }

        return $actions;
    }
}
