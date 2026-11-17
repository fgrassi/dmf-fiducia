<?php

namespace App\Filament\Pages;

use App\Actions\Reports\ExportQuotationHistoryReport;
use App\Actions\Reports\QuotationHistoryChartData;
use App\Actions\Reports\QuotationHistoryReport;
use App\Filament\Livewire\QuotationHistoryChart;
use App\Filament\Livewire\ReportSectionTable;
use App\Filament\Support\ReportFields;
use App\Filament\Support\ReportRecords;
use App\Models\FinExpenses;
use App\Models\Porto;
use App\Models\RegraDespesaCobranca;
use App\Models\TipoContainer;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * relatorioDespesasCotacao (legado), "Histórico de cotações": valores de venda das despesas das cotações Impo, Impo Aéreo e
 * Expo, agrupados (resumo por moeda) e, se pedido, a lista de despesas, com Excel. Fica no menu Relatórios.
 */
class QuotationHistory extends Page
{
    private const DEFAULTS = ['tipo_data' => 'cadastro', 'tipo' => 'impo', 'modal' => 'maritimo', 'agrupamento' => ['equipamento'], 'exibir_lista' => false];

    protected static ?string $title = 'Histórico de cotações';

    protected static ?string $navigationLabel = 'Histórico de cotações';

    protected static ?string $slug = 'relatorios/historico-cotacoes';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Relatórios';

    protected static ?int $navigationSort = 31;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** Filtros do último "Enviar": o resultado fica nas tabelas filhas, fora do estado da página. */
    public ?array $applied = null;

    /** Muda a cada "Enviar", para o resultado ser refeito mesmo com os mesmos filtros. */
    public int $runs = 0;

    /** security.yml do módulo (legado): import, export e manager, todas (lista simples no symfony 1 é E). */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->hasLegacyCredential('import') && $user->hasLegacyCredential('export') && $user->hasLegacyCredential('manager'));
    }

    public function mount(): void
    {
        $this->form->fill(self::DEFAULTS);
    }

    public function form(Schema $schema): Schema
    {
        $ports = fn (string $name, string $label) => Select::make($name)
            ->label($label)
            ->multiple()
            ->searchable()
            ->getSearchResultsUsing(fn (string $search) => Porto::query()->where('nome', 'like', "%{$search}%")->orderBy('nome')->limit(30)->pluck('nome', 'porto_id'))
            ->getOptionLabelsUsing(fn (array $values) => Porto::query()->whereKey($values)->pluck('nome', 'porto_id')->all());

        return $schema
            ->statePath('data')
            ->components([
                Grid::make(['default' => 1, 'md' => 2])
                    ->schema([
                        DatePicker::make('data_ini')
                            ->label('Data inicial'),
                        DatePicker::make('data_fim')
                            ->label('Data final'),
                        Select::make('tipo_data')
                            ->label('Filtrar por')
                            ->options(QuotationHistoryReport::DATE_AXES)
                            ->selectablePlaceholder(false),
                        ReportFields::person('cliente_id', 'Cliente'),
                        Radio::make('tipo')
                            ->label('Tipo')
                            ->options(['impo' => 'Impo', 'expo' => 'Expo'])
                            ->inline()
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set) => $get('tipo') === 'expo' && $get('modal') === 'aereo' ? $set('modal', 'maritimo') : null),
                        Radio::make('modal')
                            ->label('Modal')
                            ->options(['maritimo' => 'Marítimo', 'aereo' => 'Aéreo'])
                            ->disableOptionWhen(fn (string $value, Get $get) => $value === 'aereo' && $get('tipo') === 'expo')
                            ->inline()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('equipamento_ids', [])),
                        ReportFields::country('pais_origem_ids', 'País de origem')
                            ->multiple(),
                        $ports('porto_origem_ids', 'Porto(s) de origem'),
                        ReportFields::country('pais_destino_ids', 'País de destino')
                            ->multiple(),
                        $ports('porto_destino_ids', 'Porto(s) de destino'),
                        Select::make('despesa_ids')
                            ->label('Despesas')
                            ->multiple()
                            ->searchable()
                            ->options(fn () => FinExpenses::query()->orderBy('name')->pluck('name', 'id')),
                        Select::make('regra_ids')
                            ->label('Grupo de despesas')
                            ->multiple()
                            ->searchable()
                            ->options(fn () => RegraDespesaCobranca::query()->orderBy('nome')->pluck('nome', 'regra_despesa_cobranca_id')),
                        Radio::make('tipo_carga')
                            ->label('FCL / LCL')
                            ->options(['fcl' => 'FCL', 'lcl' => 'LCL'])
                            ->inline()
                            ->visible(fn (Get $get) => $get('modal') === 'maritimo'),
                        Select::make('equipamento_ids')
                            ->label('Equipamento')
                            ->multiple()
                            ->searchable()
                            ->options(fn (Get $get) => TipoContainer::query()->where('tipo_embarque', $get('modal') === 'aereo' ? 'a' : 'm')->orderBy('nome')->pluck('nome', 'tipo_container_id')),
                        CheckboxList::make('agrupamento')
                            ->label('Agrupar por')
                            ->options(QuotationHistoryReport::DIMENSIONS)
                            ->columns(3)
                            ->columnSpanFull(),
                        Toggle::make('exibir_lista')
                            ->label('Exibir lista de despesas'),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->livewireSubmitHandler('send')
                    ->footer([
                        Actions::make([
                            Action::make('send')
                                ->label('Enviar')
                                ->submit('send'),
                            Action::make('excel')
                                ->label('Excel - resumo')
                                ->color('gray')
                                ->action(fn () => $this->excel(false)),
                            Action::make('excelChart')
                                ->label('Excel - resumo e gráfico')
                                ->color('gray')
                                ->action(fn () => $this->excel(false, true)),
                            Action::make('excelList')
                                ->label('Excel - resumo e lista')
                                ->color('gray')
                                ->action(fn () => $this->excel(true)),
                            Action::make('clear')
                                ->label('Limpar')
                                ->color('gray')
                                ->action(fn () => $this->clear()),
                        ]),
                    ]),
                Text::make('Nenhum registro encontrado para os filtros informados.')
                    ->visible(fn () => $this->applied !== null && ! $this->hasResult()),
                Group::make(fn () => $this->sections()),
            ]);
    }

    public function send(): void
    {
        $this->applied = $this->filters();
        $this->runs++;

        if ($report = $this->build()) {
            $this->data['chart'] = QuotationHistoryChartData::defaults($report['agrupamento']);
        } else {
            $this->applied = null;
        }
    }

    public function excel(bool $withList, bool $withChart = false): ?StreamedResponse
    {
        $report = $this->build();

        return $report ? app(ExportQuotationHistoryReport::class)($report, $withList, $withChart ? app(QuotationHistoryChartData::class)($report['resumo'], $report['agrupamento'], $this->data['chart'] ?? []) : null) : null;
    }

    public function clear(): void
    {
        $this->form->fill(self::DEFAULTS);
        $this->applied = null;
    }

    /**
     * O resumo, os controles e o gráfico e, com "Exibir lista de despesas", a lista; montados na hora de desenhar, porque o conteúdo da página
     * já pode ter sido montado antes de o "Enviar" chegar. O gráfico é refeito quando as opções dele mudam.
     *
     * @return list<Livewire|Group>
     */
    private function sections(): array
    {
        if (! $this->hasResult()) {
            return [];
        }

        $table = fn (string $section) => Livewire::make(ReportSectionTable::class, fn () => ['page' => static::class, 'section' => $section, 'filters' => $this->applied])
            ->key($section.$this->runs);
        $options = $this->data['chart'] ?? [];

        return [
            $table('summary'),
            Group::make([
                Radio::make('type')
                    ->label('Tipo do gráfico')
                    ->options(QuotationHistoryChartData::TYPES)
                    ->inline()
                    ->live(),
                CheckboxList::make('x_axis')
                    ->label('Eixo X')
                    ->options(fn () => $this->chartFields())
                    ->columns(4)
                    ->live(),
                Select::make('series')
                    ->label('Série (uma linha ou barra por grupo)')
                    ->options(fn () => $this->chartFields())
                    ->placeholder('Nenhuma')
                    ->live(),
                CheckboxList::make('metrics')
                    ->label('Valores (eixo Y)')
                    ->options(QuotationHistoryChartData::METRICS)
                    ->columns(4)
                    ->live(),
            ])
                ->statePath('data.chart'),
            Livewire::make(QuotationHistoryChart::class, fn () => ['applied' => $this->applied, 'chartOptions' => $options])
                ->key('chart'.$this->runs.md5(json_encode($options))),
            ...($this->applied['exibir_lista'] ? [$table('list')] : []),
        ];
    }

    /** O último "Enviar" trouxe cotações. */
    private function hasResult(): bool
    {
        return $this->applied !== null && self::run($this->applied)['registros'] > 0;
    }

    /** @return array<string, string> campos do agrupamento enviado (e a moeda) para o eixo X e a série do gráfico */
    private function chartFields(): array
    {
        return [...array_map(fn (string $field) => QuotationHistoryReport::DIMENSIONS[$field], array_combine($this->applied['agrupamento'] ?? [], $this->applied['agrupamento'] ?? [])), 'moeda' => 'Moeda'];
    }

    /** @param array<string, mixed> $filters */
    public static function sectionTable(Table $table, string $section, array $filters): Table
    {
        $report = self::run($filters);
        $keyed = fn (array $rows) => collect($rows)->mapWithKeys(fn (array $row, int $index) => ["r{$index}" => $row])->all();
        $money = fn ($state) => number_format((float) $state, 2, ',', '.');
        $date = fn ($state) => filled($state) && ($time = strtotime($state)) ? date('d/m/Y', $time) : '';

        if ($section === 'summary') {
            return $table
                ->heading("{$report['tipo_label']} — {$report['periodo']} — {$report['registros']} registros")
                ->records(fn () => $keyed($report['resumo']))
                ->columns([
                    ...array_map(fn (string $field, int $index) => TextColumn::make("dimensoes.{$index}")
                        ->label(QuotationHistoryReport::DIMENSIONS[$field])
                        ->wrap(), $report['agrupamento'], array_keys($report['agrupamento'])),
                    TextColumn::make('moeda')
                        ->label('Moeda')
                        ->alignCenter(),
                    TextColumn::make('quantidade')
                        ->label('Quantidade')
                        ->alignEnd()
                        ->formatStateUsing(fn ($state) => number_format((int) $state, 0, ',', '.')),
                    TextColumn::make('minimo')
                        ->label('Mínimo')
                        ->alignEnd()
                        ->formatStateUsing($money),
                    TextColumn::make('maximo')
                        ->label('Máximo')
                        ->alignEnd()
                        ->formatStateUsing($money),
                    TextColumn::make('media')
                        ->label('Média')
                        ->alignEnd()
                        ->formatStateUsing($money),
                ]);
        }

        return $table
            ->heading('Despesas das cotações')
            ->records(fn (int $page, int|string $recordsPerPage, ?string $sortColumn, ?string $sortDirection) => ReportRecords::paginate($report['rows'], $page, $recordsPerPage, $sortColumn, $sortDirection))
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->columns([
                TextColumn::make('cotacao_id')
                    ->label('Cotação')
                    ->sortable()
                    ->formatStateUsing(fn ($state, array $record) => $state.(filled($record['referencia']) ? " — {$record['referencia']}" : ''))
                    ->url(fn (array $record) => filled($base = config('services.tracking.url')) ? "{$base}/gerenciador.php/{$record['cotacao_path']}?id={$record['cotacao_id']}" : null)
                    ->openUrlInNewTab(),
                TextColumn::make('data_cadastro')
                    ->label('Cadastro')
                    ->sortable()
                    ->formatStateUsing($date),
                TextColumn::make('validade')
                    ->label('Validade')
                    ->sortable()
                    ->formatStateUsing($date),
                TextColumn::make('cliente')
                    ->label('Cliente')
                    ->sortable()
                    ->wrap(),
                TextColumn::make('origem')
                    ->label('Origem')
                    ->sortable()
                    ->wrap(),
                TextColumn::make('destino')
                    ->label('Destino')
                    ->sortable()
                    ->wrap(),
                TextColumn::make('despesa_nome')
                    ->label('Despesa')
                    ->sortable()
                    ->wrap(),
                TextColumn::make('equipamento')
                    ->label('Equipamento')
                    ->sortable(),
                TextColumn::make('moeda_venda')
                    ->label('Moeda')
                    ->sortable()
                    ->alignCenter(),
                TextColumn::make('valor')
                    ->label('Valor')
                    ->sortable()
                    ->alignEnd()
                    ->formatStateUsing($money),
            ]);
    }

    /**
     * Relatório dos filtros, o mesmo para as tabelas e o Excel na requisição.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     *
     * @throws DomainException
     */
    public static function run(array $filters): array
    {
        return Cache::driver('array')->rememberForever('quotation-history:'.md5(json_encode($filters)), fn () => app(QuotationHistoryReport::class)($filters));
    }

    /** @return array<string, mixed>|null null quando o filtro é inválido (a mensagem sai como aviso) */
    private function build(): ?array
    {
        try {
            return self::run($this->filters());
        } catch (DomainException $e) {
            Notification::make()
                ->title($e->getMessage())
                ->warning()
                ->send();

            return null;
        }
    }

    /** @return array<string, mixed> */
    private function filters(): array
    {
        return Arr::except($this->form->getState(), 'chart');
    }
}
