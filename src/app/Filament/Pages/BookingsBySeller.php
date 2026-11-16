<?php

namespace App\Filament\Pages;

use App\Actions\Reports\BookingsBySellerReport;
use App\Filament\Livewire\ReportSectionTable;
use App\Filament\Resources\Embarques\EmbarqueResource;
use App\Filament\Support\ReportFields;
use App\Models\Embarque;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
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

/** relReservaVendedor (legado), "Reserva/Vendedor": fechamentos, chegadas e embarques confirmados e pagamentos recebidos por vendedor. */
class BookingsBySeller extends Page
{
    private const SECTIONS = [
        'closed' => ['Fechamentos do Período', 'Fechamento'],
        'imports' => ['IMPOS Confirmadas', 'Chegada Efetiva'],
        'exports' => ['EXPOS Confirmadas', 'Chegada Efetiva'],
        'payments' => ['PAGAMENTOS recebidos', 'Chegada Efetiva'],
    ];

    protected static ?string $title = 'Reserva/Vendedor';

    protected static ?string $navigationLabel = 'Reserva/Vendedor';

    protected static ?string $slug = 'relatorios/reserva-vendedor';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Relatórios';

    protected static ?int $navigationSort = 15;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** Filtros do último "Gerar": o resultado fica nas tabelas filhas, fora do estado da página. */
    public ?array $applied = null;

    /** @var array<string, array<string, mixed>> relatório por filtros, para as quatro tabelas da requisição não o refazerem */
    private static array $reports = [];

    /** security.yml do módulo (legado): credencial relatorio. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('relatorio');
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Grid::make(['default' => 1, 'md' => 3])
                    ->schema([
                        ReportFields::seller('vendedor_id'),
                        DatePicker::make('data_ini')
                            ->label('Período de'),
                        DatePicker::make('data_fim')
                            ->label('Período até')
                            ->afterOrEqual('data_ini'),
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
                                ->label('Gerar relatório')
                                ->submit('send'),
                        ]),
                    ]),
                ...array_map(fn (string $section) => Livewire::make(ReportSectionTable::class, fn () => ['page' => static::class, 'section' => $section, 'filters' => $this->applied])
                    ->key($section.md5(json_encode($this->applied)))
                    ->visible(fn () => $this->applied !== null), array_keys(self::SECTIONS)),
            ]);
    }

    public function send(): void
    {
        $this->applied = $this->form->getState();
    }

    /** @param array{vendedor_id?: int|string|null, data_ini?: string|null, data_fim?: string|null} $filters */
    public static function sectionTable(Table $table, string $section, array $filters): Table
    {
        [$heading, $dateLabel] = self::SECTIONS[$section];
        $rows = fn () => (self::$reports[md5(json_encode($filters))] ??= app(BookingsBySellerReport::class)(($filters['vendedor_id'] ?? null) ? (int) $filters['vendedor_id'] : null, $filters['data_ini'] ?? null, $filters['data_fim'] ?? null))[$section]
            ->map(fn (object $row) => [
                'id' => $row->embarque_id,
                'date' => $section === 'closed' ? $row->data_topo : $row->chegada_efetiva,
                'type' => Embarque::TYPES[$row->tipo_embarque] ?? $row->tipo_embarque,
                'booking' => $row->booking,
                'client' => $row->client,
                'seller' => $row->seller,
                'paid_at' => $row->paid_at ?? null,
            ])
            ->keyBy('id')
            ->all();

        return $table
            ->heading($heading)
            ->records($rows)
            ->emptyStateHeading('Não há registros.')
            ->columns([
                TextColumn::make('date')
                    ->label($dateLabel)
                    ->date('d/m/Y'),
                TextColumn::make('type')
                    ->label('Tipo'),
                TextColumn::make('booking')
                    ->label('Reserva')
                    ->url(fn (array $record) => EmbarqueResource::getUrl('view', ['record' => $record['id']])),
                TextColumn::make('client')
                    ->label('Cliente')
                    ->wrap(),
                TextColumn::make('seller')
                    ->label('Vendedor'),
                TextColumn::make('paid_at')
                    ->label('Pagamento')
                    ->date('d/m/Y')
                    ->visible($section === 'payments'),
            ]);
    }
}
