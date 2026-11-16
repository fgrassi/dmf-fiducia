<?php

namespace App\Filament\Pages;

use App\Actions\Fiducia\ClientBookingsReport;
use App\Filament\Livewire\ReportSectionTable;
use App\Filament\Resources\Embarques\EmbarqueResource;
use App\Models\Pessoa;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\UnorderedList;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ColumnGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Cache;
use UnitEnum;

/**
 * relatorioFiduciaDesconto (legado), "Relatório de Embarques - Clientes Fiducia": embarques, equipamentos e lucratividade
 * por cliente com desconto Fiducia, num intervalo de meses. Fica no menu Relatórios.
 */
class FiduciaClientBookings extends Page
{
    protected static ?string $title = 'Relatório de Embarques - Clientes Fiducia';

    protected static ?string $navigationLabel = 'Embarques - Clientes Fiducia';

    protected static ?string $slug = 'financeiro/relatorios/embarques-clientes-fiducia';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationParentItem = 'Relatórios';

    protected static ?int $navigationSort = 1;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** Filtros do último "Enviar": o resultado fica nas tabelas filhas, fora do estado da página. */
    public ?array $applied = null;

    /** Muda a cada "Enviar", para o resultado ser refeito mesmo com os mesmos filtros. */
    public int $runs = 0;

    /** security.yml do módulo (legado): (manager e financial) ou director. */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->hasLegacyCredential('director') || ($user?->hasLegacyCredential('financial') && $user?->hasLegacyCredential('manager')));
    }

    public function mount(): void
    {
        $this->form->fill($this->defaults());
    }

    public function form(Schema $schema): Schema
    {
        $years = range(2013, now()->year + 1);

        return $schema
            ->statePath('data')
            ->components([
                Grid::make(['default' => 2, 'md' => 4])
                    ->schema([
                        Select::make('mes_ini')
                            ->label('Mês início')
                            ->options(ClientBookingsReport::MONTHS)
                            ->selectablePlaceholder(false),
                        Select::make('ano_ini')
                            ->label('Ano início')
                            ->options(array_combine($years, $years))
                            ->selectablePlaceholder(false),
                        Select::make('mes_fim')
                            ->label('Mês fim')
                            ->options(ClientBookingsReport::MONTHS)
                            ->selectablePlaceholder(false),
                        Select::make('ano_fim')
                            ->label('Ano fim')
                            ->options(array_combine($years, $years))
                            ->selectablePlaceholder(false),
                    ]),
                Grid::make(['default' => 1, 'md' => 3])
                    ->schema([
                        Select::make('cliente_id')
                            ->label('Cliente')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search) => Pessoa::query()
                                ->where(fn ($query) => $query->where('nome', 'like', "%{$search}%")->orWhere('cnpj', 'like', "%{$search}%"))
                                ->orderBy('nome')
                                ->limit(30)
                                ->pluck('nome', 'pessoa_id'))
                            ->getOptionLabelUsing(fn ($value) => Pessoa::query()->whereKey($value)->value('nome')),
                        Select::make('campo_data')
                            ->label('Filtrar por data')
                            ->options(ClientBookingsReport::DATE_FIELDS)
                            ->selectablePlaceholder(false),
                        Select::make('impor_expor')
                            ->label('Tipo (IMPO/EXPO)')
                            ->options(ClientBookingsReport::TYPES)
                            ->placeholder('Todos'),
                    ]),
                Grid::make(['default' => 1, 'md' => 3])
                    ->schema([
                        Checkbox::make('agrupar_por_mes')
                            ->label('Agrupar por mês'),
                        Checkbox::make('listar_embarques')
                            ->label('Listar embarques'),
                        Checkbox::make('apenas_com_capa')
                            ->label('Somente com capa'),
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
                            Action::make('clear')
                                ->label('Limpar')
                                ->color('gray')
                                ->action(fn () => $this->clear()),
                        ]),
                    ]),
                Group::make(fn () => $this->sections()),
            ]);
    }

    public function send(): void
    {
        $this->applied = $this->form->getState();
        $this->runs++;

        try {
            self::run($this->applied);
        } catch (DomainException $e) {
            Notification::make()
                ->title($e->getMessage())
                ->warning()
                ->send();

            $this->applied = null;
        }
    }

    public function clear(): void
    {
        $this->form->fill($this->defaults());
        $this->applied = null;
    }

    /** @return array<string, mixed> */
    private function defaults(): array
    {
        return [
            'mes_ini' => now()->month, 'ano_ini' => now()->year,
            'mes_fim' => now()->month, 'ano_fim' => now()->year,
            'campo_data' => 'saida_efetiva',
        ];
    }

    /**
     * Uma tabela de clientes (por mês, se pedido), a lista de embarques de cada uma e o total geral, mais as observações. Montadas
     * na hora de desenhar, porque o conteúdo da página já pode ter sido montado antes de o "Enviar" chegar.
     *
     * @return list<Livewire|Text|Section>
     */
    private function sections(): array
    {
        if ($this->applied === null) {
            return [];
        }

        $report = self::run($this->applied);
        $tables = self::tables($report);
        $components = [];

        if (! $report['agrupar_por_mes']) {
            $components[] = Text::make("Período: {$report['qtd_meses']} mês(es) — médias = total ÷ {$report['qtd_meses']}");
        } elseif ($report['grupos'] === []) {
            $components[] = Text::make('Nenhum embarque encontrado para o filtro informado.');
        }

        $sections = [];

        foreach ($tables as $index => $table) {
            $sections[] = "table{$index}";

            if ($report['listar_embarques'] && array_filter(array_column($table['rows'], 'detalhes'))) {
                $sections[] = "details{$index}";
            }
        }

        if ($report['agrupar_por_mes'] && $report['grupos'] !== []) {
            $sections[] = 'totals';
        }

        foreach ($sections as $section) {
            $components[] = Livewire::make(ReportSectionTable::class, fn () => ['page' => static::class, 'section' => $section, 'filters' => $this->applied])
                ->key($section.$this->runs);
        }

        $components[] = Section::make('Observações')
            ->schema([UnorderedList::make(self::notes($report))]);

        return $components;
    }

    /**
     * Uma tabela por mês quando agrupado; senão, uma só.
     *
     * @param  array<string, mixed>  $report
     * @return list<array{label: ?string, rows: list<array<string, mixed>>, totais: array<string, mixed>}>
     */
    private static function tables(array $report): array
    {
        return $report['agrupar_por_mes'] ? $report['grupos'] : [['label' => null, 'rows' => $report['rows'], 'totais' => $report['totais']]];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<string>
     */
    private static function notes(array $report): array
    {
        $money = fn ($value) => 'R$ '.number_format((float) $value, 2, ',', '.');
        $total = $report['totais'];
        $notes = [
            'Confirmados: filtro pela '.($report['campo_data'] === 'chegada_efetiva' ? 'data efetiva de chegada' : 'data efetiva de embarque'),
            'Não confirmados: sem data efetiva; filtro pela '.($report['campo_data'] === 'chegada_efetiva' ? 'ETA' : 'ETD'),
            'Embarques não confirmados usam a lucratividade provisória do resumo de custos e receitas da reserva',
        ];

        if ($report['agrupar_por_mes'] && $report['grupos'] !== []) {
            $notes[] = "Médias do total geral = total ÷ {$report['qtd_meses']} mês(es)";
        }

        if ($total['lucratividade_provisoria']) {
            $notes[] = '* Lucratividade provisória: soma o resultado das capas existentes com '.$money($total['receitas_estimadas']).' em receitas e '.$money($total['despesas_estimadas']).' em despesas ainda não lançadas em capa. Conversões em dólar usam a Taxa Diária de '
                .($total['data_cotacao_dolar'] ? date('d/m/Y', strtotime($total['data_cotacao_dolar'])) : '-').' ('.number_format((float) $total['cotacao_dolar'], 4, ',', '.').').';
        }

        return $notes;
    }

    /** @param array<string, mixed> $filters */
    public static function sectionTable(Table $table, string $section, array $filters): Table
    {
        $report = self::run($filters);
        $byMonth = $report['agrupar_por_mes'];
        $int = fn ($value) => number_format((float) $value, 0, ',', '.');
        $money = fn ($value) => 'R$ '.number_format((float) $value, 2, ',', '.');
        $profit = fn ($state, array $record) => ! $record['tem_lucro'] ? '-' : $money($record['lucratividade']).($record['lucratividade_provisoria'] ? ' *' : '');
        $hint = fn (array $record) => $record['tem_lucro'] && $record['lucratividade_provisoria'] ? 'Receitas estimadas: '.$money($record['receitas_estimadas']).'; despesas estimadas: '.$money($record['despesas_estimadas']) : null;

        if (str_starts_with($section, 'details')) {
            $source = self::tables($report)[(int) substr($section, 7)];
            $rows = [];

            foreach ($source['rows'] as $row) {
                foreach ($row['detalhes'] as $detail) {
                    $rows['r'.count($rows)] = $detail + ['cliente_nome' => $row['cliente_nome']];
                }
            }

            return $table
                ->heading('Embarques'.($source['label'] ? " — {$source['label']}" : ''))
                ->records(fn () => $rows)
                ->columns([
                    TextColumn::make('cliente_nome')
                        ->label('Cliente'),
                    TextColumn::make('booking')
                        ->label('Booking')
                        ->url(fn (array $record) => EmbarqueResource::getUrl('view', ['record' => $record['embarque_id']]), shouldOpenInNewTab: true),
                    TextColumn::make('confirmado')
                        ->label('Status')
                        ->badge()
                        ->formatStateUsing(fn ($state) => $state ? 'Confirmado' : 'Não confirmado')
                        ->color(fn ($state) => $state ? 'success' : 'gray'),
                    TextColumn::make('equipamentos')
                        ->label('Equipamentos')
                        ->alignEnd()
                        ->formatStateUsing($int),
                    TextColumn::make('capas')
                        ->label('Capa')
                        ->badge()
                        ->state(fn (array $record) => array_map(fn (array $cover) => "{$cover['numero']} ({$cover['arquivo']})", $record['capas']))
                        ->placeholder('-'),
                    TextColumn::make('lucratividade')
                        ->label('Lucratividade')
                        ->alignEnd()
                        ->tooltip($hint)
                        ->formatStateUsing($profit),
                ]);
        }

        if ($section === 'totals') {
            $rows = ['total' => $report['totais']];
            $heading = 'Total geral';
        } else {
            $source = self::tables($report)[(int) substr($section, 5)];
            $rows = collect($source['rows'])->mapWithKeys(fn (array $row, int $index) => ["r{$index}" => $row])->all();
            $heading = $source['label'];

            if ($source['rows'] !== []) {
                $rows['total'] = $source['totais'];
            }
        }

        $average = fn (string $key) => fn ($state, array $record) => $int($state).(! $byMonth || $section === 'totals' ? ' (méd. '.number_format((float) $record['media_'.$key], 2, ',', '.').')' : '');
        $isTotal = fn (array $record) => in_array($record['cliente_nome'], ['Total', 'Subtotal'], true) ? FontWeight::SemiBold : null;
        $count = fn (string $key) => TextColumn::make($key)
            ->label($key === 'emb_confirmados' || $key === 'emb_nao_confirmados' ? 'Embarques' : 'Equipamentos')
            ->alignEnd()
            ->weight($isTotal)
            ->formatStateUsing($average($key));

        return $table
            ->heading($heading)
            ->records(fn () => $rows)
            ->emptyStateHeading('Nenhum cliente Fiducia encontrado para o filtro informado.')
            ->columns([
                TextColumn::make('cliente_nome')
                    ->label('Cliente')
                    ->weight($isTotal),
                ColumnGroup::make('Emb. confirmados', [$count('emb_confirmados'), $count('eq_confirmados')]),
                ColumnGroup::make('Emb. não confirmados', [$count('emb_nao_confirmados'), $count('eq_nao_confirmados')]),
                ColumnGroup::make('Totais', [
                    TextColumn::make('emb_total')
                        ->label('Embarques')
                        ->alignEnd()
                        ->weight($isTotal)
                        ->state(fn (array $record) => $int($record['emb_confirmados'] + $record['emb_nao_confirmados'])),
                    TextColumn::make('eq_total')
                        ->label('Equipamentos')
                        ->alignEnd()
                        ->weight($isTotal)
                        ->state(fn (array $record) => $int($record['eq_confirmados'] + $record['eq_nao_confirmados'])),
                ]),
                TextColumn::make('lucratividade')
                    ->label('Lucratividade')
                    ->alignEnd()
                    ->weight($isTotal)
                    ->tooltip($hint)
                    ->formatStateUsing($profit),
            ]);
    }

    /**
     * Relatório dos filtros, o mesmo para as tabelas da requisição.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     *
     * @throws DomainException filtro inválido (mesmas mensagens do legado)
     */
    private static function run(array $filters): array
    {
        return Cache::driver('array')->rememberForever('fiducia-client-bookings:'.md5(json_encode($filters)), fn () => app(ClientBookingsReport::class)($filters));
    }
}
