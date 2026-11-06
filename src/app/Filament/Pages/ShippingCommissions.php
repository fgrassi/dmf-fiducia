<?php

namespace App\Filament\Pages;

use App\Actions\Comissoes\ChangeCommissionType;
use App\Actions\Comissoes\ExportShippingCommissionsReport;
use App\Actions\Comissoes\PayCommission;
use App\Actions\Comissoes\ShippingCommissionsReport;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
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
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/** comissoes/list e comissoes/show (legado), "Relatório Comissões de Embarque": comissões por reserva e comissionado, com o pago em R$. */
class ShippingCommissions extends Page implements HasTable
{
    use InteractsWithTable;

    /** Corte da lista "Comissões em aberto" do legado (`comissoes/loadComissionados`): só criadas a partir de 2026. */
    private const CUT_OFF = '2026-01-01';

    protected static ?string $title = 'Relatório Comissões de Embarque';

    protected static ?string $navigationLabel = 'Comissões de Embarque';

    protected static ?string $slug = 'financeiro/relatorios/comissoes-de-embarque';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationParentItem = 'Relatórios';

    protected static ?int $navigationSort = 12;

    /** Relatório da requisição: a tabela e o Excel leem o mesmo resultado. */
    protected ?array $report = null;

    /** O módulo `comissoes` do legado não tem security.yml: segue a credencial financial dos demais relatórios. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('financial');
    }

    /** Vindo da lista de comissionados (`?responsavel_id=`) abre só as dele, sem o corte de data (como o `comissoes/show` do legado). */
    public function mount(): void
    {
        if ($responsible = request()->integer('responsavel_id')) {
            $this->tableFilters = $this->tableDeferredFilters = ['busca' => ['responsavel_id' => $responsible, 'criada_desde' => null]];
        }
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
            ->records(function (int $page, int|string $recordsPerPage): LengthAwarePaginator {
                $rows = $this->report()['rows'];
                $perPage = (int) $recordsPerPage;

                return new LengthAwarePaginator(
                    array_map(fn (array $row) => $row + [ArrayRecord::getKeyName() => (string) $row['id']], array_slice($rows, ($page - 1) * $perPage, $perPage)),
                    count($rows),
                    $perPage,
                    $page,
                );
            })
            ->description(function (): string {
                $totals = $this->report()['totais'];

                return 'Containers: '.$totals['containers'].' · Total de pagamentos: R$ '.number_format($totals['pago'], 2, ',', '.');
            })
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->defaultSort('data_recibo', 'desc')
            ->emptyStateHeading('Nenhum registro encontrado')
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
                                Select::make('responsavel_id')
                                    ->label('Comissionado')
                                    ->searchable()
                                    ->options(fn () => DB::table('comissao_embarque_responsavel')->orderBy('nome')->pluck('nome', 'comissao_embarque_responsavel_id')->all()),
                                TextInput::make('booking')
                                    ->label('Booking'),
                                Select::make('tipo_comissao')
                                    ->label('Tipo de comissão')
                                    ->options(ShippingCommissionsReport::COMMISSION_TYPES),
                                Select::make('tipo')
                                    ->label('Tipo')
                                    ->options(ShippingCommissionsReport::DIRECTIONS),
                                Select::make('status')
                                    ->label('Status')
                                    ->options(ShippingCommissionsReport::STATUSES),
                                DatePicker::make('criada_desde')
                                    ->label('Criadas a partir de')
                                    ->default(self::CUT_OFF),
                            ]),
                    ]),
            ])
            ->headerActions([
                Action::make('excel')
                    ->label('Excel')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('gray')
                    ->action(fn (): StreamedResponse => app(ExportShippingCommissionsReport::class)($this->report())),
            ])
            ->recordActions([
                $this->payAction(),
                $this->changeTypeAction(),
            ])
            ->columns([
                TextColumn::make('responsavel')
                    ->label('Comissionado'),
                TextColumn::make('booking')
                    ->label('Booking'),
                TextColumn::make('tipo')
                    ->label('Tipo'),
                TextColumn::make('tipo_comissao')
                    ->label('Tipo de comissão'),
                TextColumn::make('moeda')
                    ->label('Moeda'),
                TextColumn::make('containers')
                    ->label('Nº de containers')
                    ->alignCenter(),
                TextColumn::make('valor_unitario')
                    ->label('Valor unitário')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2, ',', '.')),
                TextColumn::make('pago')
                    ->label('Total de pagamentos (R$)')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2, ',', '.')),
                TextColumn::make('taxa')
                    ->label('Taxa')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state) => $state === null ? '' : number_format((float) $state, 4, ',', '.')),
                TextColumn::make('data_recibo')
                    ->label('Data do recibo')
                    ->sortable()
                    ->state(fn (array $record) => array_map(fn (string $date) => date('d/m/Y', strtotime($date)), $record['datas_recibo']))
                    ->listWithLineBreaks(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state) => $state === 'Pago' ? 'success' : 'warning'),
                TextColumn::make('obs')
                    ->label('Observação')
                    ->wrap(),
            ]);
    }

    /** addPagamento (legado): botão "$" da linha. */
    private function payAction(): Action
    {
        return Action::make('pay')
            ->label('Pagamento')
            ->icon(Heroicon::OutlinedCurrencyDollar)
            ->modalHeading('Adicionar novo pagamento')
            ->modalWidth('md')
            ->schema([
                TextInput::make('valor_total')
                    ->label('Valor total')
                    ->required()
                    ->numeric(),
                TextInput::make('taxa')
                    ->label('Taxa')
                    ->numeric(),
                DatePicker::make('data_pagamento')
                    ->label('Data do pagamento')
                    ->required()
                    ->default(now()),
                Textarea::make('observacao')
                    ->label('Observação'),
            ])
            ->action(function (array $record, array $data): void {
                app(PayCommission::class)((int) $record['id'], $data, (int) auth()->id());
                $this->report = null;
                Notification::make()->title('Pagamento lançado.')->success()->send();
            });
    }

    /** updateTipo (legado): troca do tipo e do tipo de comissão. */
    private function changeTypeAction(): Action
    {
        return Action::make('changeType')
            ->label('Tipos')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->modalHeading('Informe o novo tipo')
            ->modalWidth('md')
            ->fillForm(fn (array $record) => ['tipo' => $record['tipo_valor'], 'tipo_comissao' => $record['tipo_comissao_valor']])
            ->schema([
                Select::make('tipo')
                    ->label('Tipo')
                    ->options(ShippingCommissionsReport::DIRECTIONS)
                    ->required()
                    ->selectablePlaceholder(false),
                Select::make('tipo_comissao')
                    ->label('Tipo de comissão')
                    ->options(ShippingCommissionsReport::COMMISSION_TYPES)
                    ->required()
                    ->selectablePlaceholder(false),
            ])
            ->action(function (array $record, array $data): void {
                foreach ($data as $field => $value) {
                    app(ChangeCommissionType::class)((int) $record['id'], $field, $value);
                }

                $this->report = null;
            });
    }

    /**
     * Filtros aplicados (não os pendentes de "Filtrar") e a ordem da coluna de data do recibo.
     *
     * @return array{rows: list<array<string, mixed>>, totais: array{containers:int, pago:float}}
     */
    private function report(): array
    {
        return $this->report ??= app(ShippingCommissionsReport::class)(($this->tableFilters['busca'] ?? []) + ['dir' => $this->getTableSortDirection() === 'asc' ? 'asc' : 'desc']);
    }
}
