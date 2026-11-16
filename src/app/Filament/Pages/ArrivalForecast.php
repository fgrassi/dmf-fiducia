<?php

namespace App\Filament\Pages;

use App\Actions\Financeiro\ArrivalForecastReport;
use App\Filament\Livewire\ReportSectionTable;
use App\Filament\Resources\Embarques\EmbarqueResource;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Cache;
use UnitEnum;

/**
 * financeiro/fluxo (legado), "Previsões de Chegadas" (Fluxo de Caixa - Embarques): despesas de compra previstas das reservas
 * com chegada (IMPO) ou saída (EXPO) prevista no período, em reais. Só consulta quando há ao menos uma data, como o legado.
 */
class ArrivalForecast extends Page
{
    protected static ?string $title = 'Previsões de Chegadas';

    protected static ?string $navigationLabel = 'Previsões de Chegadas';

    protected static ?string $slug = 'financeiro/relatorios/previsoes-de-chegadas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationParentItem = 'Relatórios';

    protected static ?int $navigationSort = 20;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** Filtros do último "Enviar": o resultado fica nas tabelas filhas, fora do estado da página. */
    public ?array $applied = null;

    /** Muda a cada "Enviar", para o resultado ser refeito mesmo com os mesmos filtros. */
    public int $runs = 0;

    /** security.yml do módulo (financial) mais a checagem da ação: admin, manager ou supervisor. */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->hasLegacyCredential('financial') && $user->hasLegacyCredential(['manager', 'supervisor']));
    }

    public function mount(): void
    {
        $this->form->fill(['tipo' => 'i']);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Grid::make(['default' => 1, 'md' => 3])
                    ->schema([
                        DatePicker::make('data_ini')
                            ->label('Data Inicial'),
                        DatePicker::make('data_fim')
                            ->label('Data Final')
                            ->afterOrEqual('data_ini'),
                        Select::make('tipo')
                            ->label('Tipo de Embarque')
                            ->options(['i' => 'IMPORTAÇÃO', 'e' => 'EXPORTAÇÃO'])
                            ->selectablePlaceholder(false),
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
                                ->label('Filtrar')
                                ->submit('send'),
                            Action::make('print')
                                ->label('Imprimir')
                                ->color('gray')
                                ->alpineClickHandler('window.print()'),
                        ]),
                    ]),
                Text::make('Informe o período para gerar o fluxo de caixa.')
                    ->visible(fn () => $this->applied === null),
                Text::make('Nenhum embarque encontrado para o filtro informado.')
                    ->visible(fn () => $this->applied !== null && $this->report() === ['sea' => [], 'other' => []]),
                Group::make(fn () => $this->sections()),
            ]);
    }

    public function send(): void
    {
        $this->applied = $this->form->getState();
        $this->runs++;
    }

    /**
     * Uma tabela por reserva (com as despesas de cada BL e o total do BL) e, no fim, o total geral, sob o mesmo "Enviar". Montadas
     * na hora de desenhar, porque o conteúdo da página já pode ter sido montado antes de o "Enviar" chegar.
     *
     * @return list<Livewire>
     */
    private function sections(): array
    {
        $count = count(self::bookings($this->report()));
        $sections = $count === 0 ? [] : [...array_map(fn (int $index) => "booking{$index}", range(0, $count - 1)), 'totals'];

        return array_map(fn (string $section) => Livewire::make(ReportSectionTable::class, fn () => ['page' => static::class, 'section' => $section, 'filters' => $this->applied])
            ->key($section.$this->runs), $sections);
    }

    /** @return array{sea: list<array<string, mixed>>, other: list<array<string, mixed>>} */
    private function report(): array
    {
        return $this->applied === null ? ['sea' => [], 'other' => []] : self::run($this->applied);
    }

    /**
     * As reservas marítimas e depois as demais, cada uma com o rótulo da transportadora.
     *
     * @param  array{sea: list<array<string, mixed>>, other: list<array<string, mixed>>}  $report
     * @return list<array<string, mixed>>
     */
    private static function bookings(array $report): array
    {
        return [
            ...array_map(fn (array $booking) => $booking + ['carrier_label' => 'Armador'], $report['sea']),
            ...array_map(fn (array $booking) => $booking + ['carrier_label' => 'Cia Aérea/Cia Seguro'], $report['other']),
        ];
    }

    /** @param array<string, mixed> $filters */
    public static function sectionTable(Table $table, string $section, array $filters): Table
    {
        $bookings = self::bookings(self::run($filters));
        $money = fn (string $symbol, $value) => trim("{$symbol} ".number_format((float) $value, 2, ',', '.'));
        $date = fn ($value) => $value ? date('d/m/Y', strtotime($value)) : '';

        if ($section === 'totals') {
            $total = array_sum(array_map(fn (array $booking) => array_sum(array_column($booking['groups'], 'total')), $bookings));

            return $table
                ->heading('Total geral')
                ->records(fn () => ['total' => ['reservas' => count($bookings), 'total' => $total]])
                ->columns([
                    TextColumn::make('reservas')
                        ->label('Reservas'),
                    TextColumn::make('total')
                        ->label('Valor Convertido')
                        ->alignEnd()
                        ->weight(FontWeight::SemiBold)
                        ->formatStateUsing(fn ($state) => $money('R$', $state)),
                ]);
        }

        $booking = $bookings[(int) substr($section, 7)];
        $rows = [];

        foreach ($booking['groups'] as $group) {
            foreach ($group['expenses'] as $line) {
                $rows['r'.count($rows)] = $line + ['group' => $group['title'], 'is_total' => false];
            }

            $rows['r'.count($rows)] = ['group' => $group['title'], 'name' => 'Total', 'converted' => $group['total'], 'is_total' => true];
        }

        $bold = fn (array $record) => $record['is_total'] ? FontWeight::SemiBold : null;

        return $table
            ->heading("{$booking['booking']}({$booking['embarque_id']})")
            ->description("Cliente: {$booking['cliente']} | {$booking['carrier_label']}: {$booking['transportadora']} | ETD: {$date($booking['etd'])} | ETA: {$date($booking['eta'])}")
            ->headerActions([
                Action::make('open')
                    ->label('Abrir embarque')
                    ->color('gray')
                    ->url(EmbarqueResource::getUrl('view', ['record' => $booking['embarque_id']])),
            ])
            ->records(fn () => $rows)
            ->columns([
                TextColumn::make('group')
                    ->label('BL / Grupo'),
                TextColumn::make('name')
                    ->label('Despesa')
                    ->weight($bold),
                TextColumn::make('billing')
                    ->label('Tipo')
                    ->badge(),
                TextColumn::make('factor')
                    ->label('Fator')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state) => filled($state) ? str_replace('.', ',', (string) round((float) $state, 4)) : ''),
                TextColumn::make('unit_cost')
                    ->label('Compra Unit.')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state, array $record) => $record['is_total'] ? '' : $money($record['currency'], $state)),
                TextColumn::make('total_cost')
                    ->label('Compra Total')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state, array $record) => $record['is_total'] ? '' : $money($record['currency'], $state)),
                TextColumn::make('rate')
                    ->label('Cotação')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state) => filled($state) ? number_format((float) $state, 4, ',', '.') : ''),
                TextColumn::make('converted')
                    ->label('Valor Convertido')
                    ->alignEnd()
                    ->weight($bold)
                    ->formatStateUsing(fn ($state) => $money('R$', $state)),
            ]);
    }

    /**
     * Relatório dos filtros, o mesmo para as tabelas da requisição.
     *
     * @param  array<string, mixed>  $filters
     * @return array{sea: list<array<string, mixed>>, other: list<array<string, mixed>>}
     */
    private static function run(array $filters): array
    {
        return Cache::driver('array')->rememberForever('arrival-forecast:'.md5(json_encode($filters)), fn () => app(ArrivalForecastReport::class)($filters['tipo'], $filters['data_ini'] ?? null, $filters['data_fim'] ?? null));
    }
}
