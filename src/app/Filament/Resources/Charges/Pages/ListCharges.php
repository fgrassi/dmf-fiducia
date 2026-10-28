<?php

namespace App\Filament\Resources\Charges\Pages;

use App\Actions\Cobranca\Generation\BookingChecks;
use App\Actions\Cobranca\Generation\GenerateAllCharges;
use App\Actions\Cobranca\Generation\GenerateManualCharge;
use App\Actions\Cobranca\Listing\BuildChargeListQuery;
use App\Actions\Cobranca\Listing\ExportCharges;
use App\Actions\Cobranca\Nfse\SyncPendingNfse;
use App\Filament\Resources\Charges\ChargeResource;
use App\Models\Embarque;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;

class ListCharges extends ListRecords
{
    protected static string $resource = ChargeResource::class;

    /** Máximo de consultas à API de NFS-e ao abrir a lista (sincronizarStatusNfsePendentes do legado). */
    private const NFSE_SYNC_LIMIT = 15;

    /** Chave (filtros, busca, aba, página, ordem) da última página sincronizada: a lista só sincroniza quando ela muda. */
    #[Locked]
    public string $nfseSyncedFor = '';

    /**
     * executeList (legado): a cada carga da lista, alinha com a API as NFS-e pendentes da página exibida (até 15 consultas)
     * e reenvia o e-mail de autorização que não saiu.
     */
    public function getTableRecords(): Collection|Paginator|CursorPaginator
    {
        $records = parent::getTableRecords();
        $key = md5(json_encode([$this->tableFilters, $this->tableSearch, $this->activeTab, $this->getTablePage(), $this->tableSort]));

        if ($key === $this->nfseSyncedFor) {
            return $records;
        }

        $this->nfseSyncedFor = $key;
        $this->syncNfse($records instanceof Paginator || $records instanceof CursorPaginator ? $records->getCollection() : $records);
        $this->cachedTableRecords = null;

        return parent::getTableRecords();
    }

    /** sincronizarStatusNfsePendentes: 1º laço consulta a API (limite de 15), 2º laço reenvia o e-mail de todas as da página. */
    private function syncNfse(Collection $charges): void
    {
        $sync = app(SyncPendingNfse::class);
        $refreshed = 0;

        foreach ($charges as $charge) {
            if ($refreshed >= self::NFSE_SYNC_LIMIT) {
                break;
            }

            $refreshed += (int) $sync->refresh($charge);
        }

        foreach ($charges as $charge) {
            $sync->retryEmail($charge);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            // executeGeraCobranca do legado (botão "Gerar manualmente"): todas as reservas pendentes
            Action::make('gerarTodas')
                ->label('Gerar cobranças')
                ->icon('heroicon-o-arrow-path')
                ->requiresConfirmation()
                ->modalHeading('Gerar cobranças')
                ->modalDescription('Gera as ICs de todas as reservas com despesas pendentes e envia os documentos aos clientes.')
                ->modalSubmitActionLabel('Gerar')
                ->action(function (Action $action) {
                    try {
                        $created = app(GenerateAllCharges::class)(auth()->id());
                    } catch (DomainException $e) {
                        Notification::make()
                            ->title($e->getMessage())
                            ->warning()
                            ->send();
                        $action->halt();
                    }

                    self::notifyCreated($created);
                }),
            // geraCobrancaManual do legado (botão "Gerar Cobrança" da reserva)
            Action::make('gerarManual')
                ->label('Gerar cobrança da reserva')
                ->icon('heroicon-o-document-plus')
                ->modalHeading('Gerar cobrança manualmente')
                ->modalDescription('A IC é gerada com as despesas pendentes da reserva e o documento é enviado ao cliente.')
                ->modalSubmitActionLabel('Gerar')
                ->schema([
                    Select::make('embarque_id')
                        ->label('Reserva')
                        ->required()
                        ->searchable()
                        ->live()
                        ->getSearchResultsUsing(fn (string $search) => Embarque::query()
                            ->where('booking', 'like', "%{$search}%")
                            ->orderByDesc('embarque_id')
                            ->limit(30)
                            ->pluck('booking', 'embarque_id'))
                        ->getOptionLabelUsing(fn ($value) => Embarque::find($value)?->booking)
                        ->afterStateUpdated(fn (Set $set) => $set('despesas', [])),
                    CheckboxList::make('despesas')
                        ->label('Despesas')
                        ->helperText('Nenhuma marcada = todas as despesas pendentes.')
                        ->options(fn (Get $get) => $get('embarque_id') ? self::pendingExpenses(Embarque::find($get('embarque_id'))) : [])
                        ->visible(fn (Get $get) => filled($get('embarque_id')))
                        ->columns(2),
                ])
                ->action(function (array $data, Action $action) {
                    try {
                        $created = app(GenerateManualCharge::class)(
                            Embarque::findOrFail($data['embarque_id']),
                            array_map('intval', $data['despesas'] ?? []),
                            auth()->user(),
                        );
                    } catch (DomainException $e) {
                        Notification::make()
                            ->title($e->getMessage())
                            ->danger()
                            ->send();
                        $action->halt();
                    }

                    self::notifyCreated($created);
                }),
            // executeExcel do legado: exporta o filtro atual em Abertos / Pagos
            Action::make('exportar')
                ->label('Exportar XLSX')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => app(ExportCharges::class)($this->tableFilters['busca'] ?? [])),
        ];
    }

    private static function notifyCreated(int $created): void
    {
        $created
            ? Notification::make()
                ->title($created === 1 ? '1 cobrança gerada com sucesso!' : "{$created} cobranças geradas com sucesso!")
                ->success()
                ->send()
            : Notification::make()
                ->title('Nenhuma cobrança gerada!')
                ->body('Os motivos (despesas, cotações, liberação, invoice ou tomador) foram enviados por e-mail ao financeiro.')
                ->warning()
                ->send();
    }

    /** Despesas da reserva com venda ainda fora de IC (as que a geração manual pode escolher). @return array<int, string> */
    private static function pendingExpenses(?Embarque $b): array
    {
        if (! $b) {
            return [];
        }

        return BookingChecks::unbilled($b)
            ->join('fin_expenses as fe', 'fe.id', '=', 'f.ref_expense')
            ->leftJoin('cmn_currencies as c', 'c.id', '=', 'f.ref_currency_sr')
            ->where('f.selling_rate', '>', 0)
            ->orderBy('fe.name')
            ->get(['f.id', 'fe.name', 'c.name as currency', 'f.selling_rate'])
            ->mapWithKeys(fn ($e) => [$e->id => "{$e->name} — {$e->currency} ".number_format((float) $e->selling_rate, 2, ',', '.')])
            ->all();
    }

    /** "Exibir" do legado: Abertos (padrão) / Pagos / Todos. */
    public function getTabs(): array
    {
        $show = fn (string $mode) => fn (Builder $query) => app(BuildChargeListQuery::class)->applyShow($query, $mode);

        return [
            'abertos' => Tab::make('Abertos')->modifyQueryUsing($show('abertos')),
            'pagos' => Tab::make('Pagos')->modifyQueryUsing($show('pagos')),
            'todos' => Tab::make('Todos'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'abertos';
    }
}
