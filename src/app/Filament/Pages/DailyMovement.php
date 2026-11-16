<?php

namespace App\Filament\Pages;

use App\Actions\Reports\DailyMovementReport;
use App\Enums\OperationType;
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
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** relConfReservas (legado), "Movimento Diário": reservas, seguros, desembaraços e cotações do período, com totais. */
class DailyMovement extends Page
{
    private const SECTIONS = ['bookings', 'expo', 'impo', 'air'];

    protected static ?string $title = 'Movimento Diário';

    protected static ?string $navigationLabel = 'Movimento Diário';

    protected static ?string $slug = 'relatorios/movimento-diario';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Relatórios';

    protected static ?int $navigationSort = 20;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** Filtros do último "Enviar": o resultado fica nas tabelas filhas, fora do estado da página. */
    public ?array $applied = null;

    /** @var array<string, array<string, mixed>> relatório por filtros, para as quatro tabelas da requisição não o refazerem */
    private static array $reports = [];

    /** security.yml do módulo (legado): credenciais director, manager ou import. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential(['director', 'manager', 'import']);
    }

    public function mount(): void
    {
        $this->form->fill(['data_ini' => now()->toDateString(), 'data_fim' => now()->toDateString()]);
        $this->send();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Grid::make(['default' => 1, 'md' => 3])
                    ->schema([
                        DatePicker::make('data_ini')
                            ->label('Data inicial')
                            ->required(),
                        DatePicker::make('data_fim')
                            ->label('Data final')
                            ->required()
                            ->afterOrEqual('data_ini'),
                        Select::make('impor_expor')
                            ->label('Tipo')
                            ->options(OperationType::class),
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
                        ]),
                    ]),
                ...array_map(fn (string $section) => Livewire::make(ReportSectionTable::class, fn () => ['page' => static::class, 'section' => $section, 'filters' => $this->applied])
                    ->key($section.md5(json_encode($this->applied)))
                    ->visible(fn () => $this->applied !== null), self::SECTIONS),
            ]);
    }

    public function send(): void
    {
        $this->applied = $this->form->getState();
    }

    /** @param array{data_ini: string, data_fim: string, impor_expor?: string|null} $filters */
    public static function sectionTable(Table $table, string $section, array $filters): Table
    {
        $report = fn () => self::report($filters);
        $keyed = fn (array $rows) => collect($rows)->mapWithKeys(fn (array $row, int $index) => ["r{$index}" => $row])->all();
        $highlight = fn (array $record) => ($record['booked'] ?? false) ? 'bg-yellow-100 dark:bg-yellow-900/30' : null;

        return match ($section) {
            'bookings' => $table
                ->heading('Reservas')
                ->records(fn () => $keyed([...$report()['bookings'], ...$report()['insurances'], ...$report()['clearances']]))
                ->emptyStateHeading('Nenhuma reserva no período')
                ->columns([
                    TextColumn::make('date')
                        ->label('Data Reserva'),
                    TextColumn::make('type')
                        ->label('Tipo')
                        ->badge()
                        ->color(fn (array $record) => OperationType::tryFrom((string) ($record['operation'] ?? ''))?->getColor()),
                    TextColumn::make('star')
                        ->label('')
                        ->formatStateUsing(fn ($state) => $state ? '★' : ''),
                    TextColumn::make('booking')
                        ->label('Booking')
                        ->url(fn (array $record) => EmbarqueResource::getUrl('view', ['record' => $record['id']])),
                    TextColumn::make('client')
                        ->label('Cliente')
                        ->wrap(),
                    TextColumn::make('seller')
                        ->label('Vendedor'),
                    TextColumn::make('carrier')
                        ->label('Armador'),
                    TextColumn::make('agent')
                        ->label('Agente')
                        ->wrap(),
                    TextColumn::make('origin')
                        ->label('Origem')
                        ->wrap(),
                    TextColumn::make('destination')
                        ->label('Destino')
                        ->wrap(),
                    TextColumn::make('containers')
                        ->label('Ctr')
                        ->placeholder('-'),
                    TextColumn::make('manager')
                        ->label('Responsável')
                        ->wrap(),
                ]),
            'expo' => $table
                ->heading('Cotações Expo')
                ->records(fn () => $keyed($report()['quotes']['expo']))
                ->recordClasses($highlight)
                ->emptyStateHeading('Nenhuma cotação no período')
                ->columns([
                    TextColumn::make('client')
                        ->label('Cliente')
                        ->wrap(),
                    TextColumn::make('reference')
                        ->label('Referência'),
                    TextColumn::make('carrier')
                        ->label('Armador'),
                    TextColumn::make('product')
                        ->label('Produto'),
                    TextColumn::make('equipment')
                        ->label('Equipamentos')
                        ->listWithLineBreaks(),
                    TextColumn::make('origin')
                        ->label('Origem'),
                    TextColumn::make('destination')
                        ->label('Destino'),
                    TextColumn::make('transshipment')
                        ->label('Transbordo'),
                    TextColumn::make('validity')
                        ->label('Validade'),
                ]),
            'impo' => $table
                ->heading('Cotações Impo')
                ->records(fn () => $keyed($report()['quotes']['impo']))
                ->recordClasses($highlight)
                ->emptyStateHeading('Nenhuma cotação no período')
                ->columns([
                    TextColumn::make('client')
                        ->label('Cliente')
                        ->wrap(),
                    TextColumn::make('reference')
                        ->label('Referência'),
                    TextColumn::make('agent')
                        ->label('Agente')
                        ->wrap(),
                    TextColumn::make('carrier')
                        ->label('Armador'),
                    TextColumn::make('origin')
                        ->label('Origem'),
                    TextColumn::make('destination')
                        ->label('Destino'),
                    TextColumn::make('transshipment')
                        ->label('Transbordo'),
                ]),
            default => $table
                ->heading('Cotações Impo Aéreo')
                ->records(fn () => $keyed($report()['quotes']['air']))
                ->recordClasses($highlight)
                ->emptyStateHeading('Nenhuma cotação no período')
                ->columns([
                    TextColumn::make('client')
                        ->label('Cliente')
                        ->wrap(),
                    TextColumn::make('reference')
                        ->label('Referência'),
                    TextColumn::make('agent')
                        ->label('Agente')
                        ->wrap(),
                    TextColumn::make('airline')
                        ->label('Cia Aérea'),
                    TextColumn::make('origin')
                        ->label('Origem'),
                    TextColumn::make('destination')
                        ->label('Destino'),
                    TextColumn::make('route')
                        ->label('Rota'),
                ]),
        };
    }

    /**
     * @param  array{data_ini: string, data_fim: string, impor_expor?: string|null}  $filters
     * @return array<string, mixed>
     */
    private static function report(array $filters): array
    {
        return self::$reports[md5(json_encode($filters))] ??= app(DailyMovementReport::class)($filters['data_ini'], $filters['data_fim'], $filters['impor_expor'] ?? null);
    }
}
