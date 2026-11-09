<?php

namespace App\Filament\Pages;

use App\Actions\Financeiro\ExportTaReport;
use App\Actions\Financeiro\GiveTaPayment;
use App\Actions\Financeiro\TaReport as TaReportQuery;
use App\Filament\Resources\OtherPaymentOrders\OtherPaymentOrderResource;
use App\Models\Pessoa;
use App\Services\Cobranca\ChargeDetails;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * relatorioTa (legado), "Relatório TA", no menu Relatórios: reservas com a despesa TA, o que já foi baixado e o saldo, com o
 * Excel e a ação "Dar baixa" (cria a baixa de TA e a ordem de pagamento, em uso desde 09/2026). Credencial do legado:
 * gerente, diretor, ou financeiro com supervisor.
 */
class TaReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = 'Relatório TA';

    protected static ?string $navigationLabel = 'Relatório TA';

    protected static ?string $slug = 'financeiro/relatorios/relatorio-ta';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationParentItem = 'Relatórios';

    protected static ?int $navigationSort = 22;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->hasLegacyCredential(['manager', 'director'])
            || ($user?->hasLegacyCredential('financial') && $user?->hasLegacyCredential('supervisor')));
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                EmbeddedTable::make(),
            ]);
    }

    /** @return array<string, mixed> */
    private function search(): array
    {
        return $this->getTableFilterState('busca') ?? [];
    }

    public function excel(): StreamedResponse
    {
        return app(ExportTaReport::class)(app(TaReportQuery::class)($this->search()));
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (TaReportQuery $report): Collection => $report($this->search())->keyBy('embarque_id'))
            ->paginated(false)
            ->heading(function (): string {
                $rows = $this->getTableRecords();
                $format = fn (string $column) => number_format($rows->sum($column), 2, ',', '.');

                return $rows->isEmpty() ? '' : "Total: {$rows->count()} reservas · TA {$format('total_ta')} · Valor pago {$format('valor_pago')} · Saldo {$format('saldo')}";
            })
            ->emptyStateHeading('Nenhum registro encontrado')
            ->recordClasses(fn (array $record) => filled($record['saida_efetiva']) ? 'bg-green-100 dark:bg-green-950/40' : null)
            ->selectable()
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
                                Select::make('shipper_id')
                                    ->label('Shipper')
                                    ->searchable()
                                    ->getSearchResultsUsing(fn (string $search) => Pessoa::query()
                                        ->where(fn ($query) => $query->where('nome', 'like', "%{$search}%")->orWhere('cnpj', 'like', "%{$search}%"))
                                        ->orderBy('nome')
                                        ->limit(30)
                                        ->pluck('nome', 'pessoa_id'))
                                    ->getOptionLabelUsing(fn ($value) => Pessoa::query()->whereKey($value)->value('nome')),
                                TextInput::make('booking')
                                    ->label('Booking'),
                                TextInput::make('fatura')
                                    ->label('Nr. Fatura'),
                                $this->portSelect('porto_origem_id', 'Origem'),
                                $this->portSelect('porto_destino_id', 'Destino'),
                                Select::make('pago')
                                    ->label('Pago')
                                    ->options(['s' => 'Sim', 'p' => 'Parcial', 'n' => 'Não']),
                                Select::make('recebido')
                                    ->label('Recebido (Cliente)')
                                    ->options(['s' => 'Sim', 'n' => 'Não']),
                                Checkbox::make('embarques_confirmados')
                                    ->label('Somente embarques confirmados')
                                    ->inline(false),
                                DatePicker::make('etd_ini')
                                    ->label('ETD de'),
                                DatePicker::make('etd_fim')
                                    ->label('ETD até'),
                                DatePicker::make('saida_efetiva_ini')
                                    ->label('Saída efetiva de'),
                                DatePicker::make('saida_efetiva_fim')
                                    ->label('Saída efetiva até'),
                            ]),
                    ]),
            ])
            ->headerActions([
                Action::make('excel')
                    ->label('Excel')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('gray')
                    ->action(fn () => $this->excel()),
            ])
            ->columns([
                TextColumn::make('shipper')
                    ->label('Shipper')
                    ->wrap(),
                TextColumn::make('booking')
                    ->label('Booking'),
                TextColumn::make('fatura')
                    ->label('Nr. Fatura'),
                TextColumn::make('origem')
                    ->label('Origem'),
                TextColumn::make('destino')
                    ->label('Destino'),
                TextColumn::make('etd')
                    ->label('ETD')
                    ->date('d/m/Y'),
                TextColumn::make('saida_efetiva')
                    ->label('Saída Efetiva')
                    ->date('d/m/Y'),
                TextColumn::make('qtd_container')
                    ->label('Quantidade de container'),
                TextColumn::make('total_ta')
                    ->label('Total TA')
                    ->numeric(2, ',', '.'),
                TextColumn::make('recebido')
                    ->label('Recebido (Cliente)'),
                TextColumn::make('embarque_pago')
                    ->label('Pago')
                    ->description(fn (array $record) => $record['qtd_baixas'] ? "{$record['qtd_baixas']} baixa(s)" : null)
                    ->url(fn (array $record) => $record['qtd_baixas'] && $record['ordem_pagamento_id']
                        ? OtherPaymentOrderResource::getUrl('view', ['record' => $record['ordem_pagamento_id']])
                        : null, shouldOpenInNewTab: true),
                TextColumn::make('taxa_pagamento')
                    ->label('Taxa de Pagamento')
                    ->numeric(4, ',', '.'),
                TextColumn::make('valor_pago')
                    ->label('Valor pago')
                    ->numeric(2, ',', '.'),
                TextColumn::make('saldo')
                    ->label('Saldo')
                    ->numeric(2, ',', '.'),
                TextColumn::make('valor_ta_rs')
                    ->label('Valor TA R$')
                    ->numeric(2, ',', '.'),
            ])
            ->toolbarActions([
                BulkAction::make('baixar')
                    ->label('Dar baixa')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->modalHeading('Dar baixa TA')
                    ->modalWidth('5xl')
                    ->modalSubmitActionLabel('Confirmar')
                    ->fillForm(function (Collection $records): array {
                        $rates = $records->pluck('taxa_sugerida')->filter()->unique();

                        return [
                            'taxa' => $rates->count() === 1 ? $rates->first() : null,
                            'pagamentos' => $records->map(fn (array $record) => [
                                'embarque_id' => $record['embarque_id'],
                                'reserva' => trim("{$record['shipper']} — {$record['booking']}".($record['has_op'] ? '' : ' (OP será gerada)')),
                                'saldo' => number_format($record['saldo'], 2, '.', ''),
                                'valor_pago' => $record['prefill_valor_pago'] ?: null,
                            ])->values()->all(),
                        ];
                    })
                    ->schema([
                        TextInput::make('taxa')
                            ->label('Nova taxa de câmbio')
                            ->numeric()
                            ->required()
                            ->minValue(0.0001)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Get $get, Set $set, $state) {
                                if ((float) $state <= 0) {
                                    return;
                                }

                                collect($get('pagamentos'))->each(fn (array $item, string $key) => $set("pagamentos.{$key}.valor_pago", round((float) $item['saldo'] * (float) $state, 2)));
                            }),
                        Repeater::make('pagamentos')
                            ->label('Reservas')
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->schema([
                                Hidden::make('embarque_id'),
                                Grid::make(['default' => 1, 'md' => 3])
                                    ->schema([
                                        TextInput::make('reserva')
                                            ->label('Shipper — Booking')
                                            ->disabled()
                                            ->dehydrated(false),
                                        TextInput::make('saldo')
                                            ->label('Saldo (TA)')
                                            ->disabled()
                                            ->dehydrated(false),
                                        TextInput::make('valor_pago')
                                            ->label('Valor pago (R$)')
                                            ->numeric()
                                            ->required()
                                            ->minValue(0.01),
                                    ]),
                            ]),
                    ])
                    ->action(function (array $data, GiveTaPayment $give) {
                        try {
                            $result = $give(
                                collect($data['pagamentos'])->mapWithKeys(fn (array $item) => [(int) $item['embarque_id'] => $item['valor_pago']])->all(),
                                (float) $data['taxa'],
                                auth()->id(),
                            );
                        } catch (DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()
                            ->title("{$result['processed']} item(ns) baixado(s) com sucesso.")
                            ->body($result['errors'] === [] ? null : implode("\n", $result['errors']))
                            ->success()
                            ->send();

                        $this->flushCachedTableRecords();
                    })
                    ->deselectRecordsAfterCompletion(),
            ]);
    }

    private function portSelect(string $name, string $label): Select
    {
        return Select::make($name)
            ->label($label)
            ->searchable()
            ->getSearchResultsUsing(fn (string $search) => DB::table('porto as p')
                ->leftJoin('paises as c', 'c.paises_id', '=', 'p.ref_pais')
                ->where('p.nome', 'like', "%{$search}%")
                ->orderBy('p.nome')
                ->limit(30)
                ->get(['p.porto_id', 'p.nome', 'c.nome as pais'])
                ->mapWithKeys(fn ($port) => [$port->porto_id => "{$port->nome} ({$port->pais})"])
                ->all())
            ->getOptionLabelUsing(fn ($value) => app(ChargeDetails::class)->port((int) $value));
    }
}
