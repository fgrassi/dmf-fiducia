<?php

namespace App\Filament\Pages;

use App\Actions\Reports\BookingsReport;
use App\Actions\Reports\ExportSpreadsheet;
use App\Enums\OperationType;
use App\Filament\Livewire\ReportSectionTable;
use App\Filament\Resources\Embarques\EmbarqueResource;
use App\Filament\Support\ReportFields;
use App\Models\Embarque;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/** relReserva (legado), "Reservas": reservas por filtros em cinco seções (marítimo, LCL, aéreo, seguro e desembaraço), com planilha. */
class BookingsReportPage extends Page
{
    private const SECTIONS = ['maritime', 'lcl', 'air', 'insurance', 'clearance'];

    private const EQUIPMENT = ['20' => '20', '40' => '40', 'lcl' => 'LCL', 'aereo' => 'Aéreo', 'roro' => 'Ro-Ro', 'tank' => 'Tank', 'outro' => 'Outro'];

    protected static ?string $title = 'Reservas';

    protected static ?string $navigationLabel = 'Reservas';

    protected static ?string $slug = 'relatorios/reservas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Relatórios';

    protected static ?int $navigationSort = 10;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** Filtros do último "Gerar": o resultado fica nas tabelas filhas, fora do estado da página. */
    public ?array $applied = null;

    /** @var array<string, array<string, mixed>> relatório por filtros, para as cinco tabelas da requisição não o refazerem */
    private static array $reports = [];

    /** security.yml do módulo (legado): credencial relatorio. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('relatorio');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Exportar XLS')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->visible(fn () => $this->applied !== null)
                ->action(fn () => $this->export()),
        ];
    }

    public function mount(): void
    {
        $this->form->fill(['sections' => ['maritime', 'air', 'insurance', 'clearance']]);
    }

    public function form(Schema $schema): Schema
    {
        $date = fn (string $name, string $label) => DatePicker::make($name)->label($label);

        return $schema
            ->statePath('data')
            ->components([
                Section::make('Reserva')
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 4])
                            ->schema([
                                $date('from', 'Cadastro de'),
                                $date('to', 'Cadastro até'),
                                TextInput::make('booking')
                                    ->label('Booking'),
                                TextInput::make('invoice')
                                    ->label('Fatura'),
                                Select::make('operation')
                                    ->label('Tipo de reserva')
                                    ->options(OperationType::class),
                                Select::make('sellers')
                                    ->label('Vendedores')
                                    ->multiple()
                                    ->searchable()
                                    ->options(fn () => DB::table('vendedor')->whereNull('deleted_at')->orderBy('nome')->pluck('nome', 'vendedor_id')->all()),
                                Select::make('status')
                                    ->label('Status')
                                    ->options(Embarque::STATUSES)
                                    ->helperText('Sem status, não lista as canceladas.'),
                                TextInput::make('closing_by')
                                    ->label('Fechamento (responsável)'),
                                Select::make('closing_type')
                                    ->label('Tipo de fechamento')
                                    ->options(['o' => 'Origem / DMF', 'd' => 'Destino / Agente']),
                                Select::make('loading_type')
                                    ->label('Embarque')
                                    ->options(['d' => 'DMF', 'f' => 'Free Hand']),
                            ]),
                    ]),
                Section::make('Partes e rota')
                    ->collapsed()
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 3])
                            ->schema([
                                ReportFields::person('exporter', 'Exportador'),
                                ReportFields::person('importer', 'Importador'),
                                ReportFields::person('client', 'Cliente'),
                                Select::make('company_group')
                                    ->label('Grupo de Empresa (clientes)')
                                    ->searchable()
                                    ->options(fn () => DB::table('grupo_empresa')->orderBy('nome')->pluck('nome', 'grupo_empresa_id')->all()),
                                ReportFields::person('agent', 'Agente'),
                                Select::make('broker')
                                    ->label('Despachante')
                                    ->searchable()
                                    ->options(fn () => DB::table('despachante')->orderBy('nome')->pluck('nome', 'despachante_id')->all()),
                                Select::make('dmf_broker')
                                    ->label('Despachante DMF')
                                    ->searchable()
                                    ->options(fn () => DB::table('despachante')->orderBy('nome')->pluck('nome', 'despachante_id')->all()),
                                ReportFields::carrier('carrier'),
                                ReportFields::ship('ship', 'Navio'),
                                TextInput::make('via')
                                    ->label('Via'),
                                ReportFields::country('origin_country', 'País de origem'),
                                ReportFields::port('origin_port', 'Porto de origem'),
                                ReportFields::country('destination_country', 'País de destino'),
                                ReportFields::port('destination_port', 'Porto de destino'),
                                TextInput::make('place_of_delivery')
                                    ->label('Place of delivery'),
                            ]),
                    ]),
                Section::make('Datas')
                    ->collapsed()
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 4])
                            ->schema([
                                $date('etd_from', 'ETD de'),
                                $date('etd_to', 'ETD até'),
                                $date('exit_from', 'Saída efetiva de'),
                                $date('exit_to', 'Saída efetiva até'),
                                $date('eta_from', 'ETA de'),
                                $date('eta_to', 'ETA até'),
                                $date('arrival_from', 'Chegada efetiva de'),
                                $date('arrival_to', 'Chegada efetiva até'),
                            ]),
                    ]),
                Section::make('Equipamento e seções')
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 2])
                            ->schema([
                                CheckboxList::make('equipment')
                                    ->label('Tipo de equipamento (grupo)')
                                    ->options(self::EQUIPMENT)
                                    ->columns(4),
                                CheckboxList::make('sections')
                                    ->label('Seções')
                                    ->options(['maritime' => 'Marítimo (e LCL)', 'air' => 'Aéreo', 'insurance' => 'Seguro', 'clearance' => 'Desembaraço'])
                                    ->columns(4),
                            ]),
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
                    ->visible(fn () => $this->applied !== null && static::visible($section, $this->applied)), self::SECTIONS),
            ]);
    }

    public function send(): void
    {
        $this->applied = $this->form->getState();
    }

    /** A seção marítima traz também o LCL, como as caixas do legado. */
    private static function visible(string $section, array $filters): bool
    {
        $chosen = $filters['sections'] ?? [];

        return in_array($section === 'lcl' ? 'maritime' : $section, $chosen, true);
    }

    /** @param array<string, mixed> $filters */
    public static function sectionTable(Table $table, string $section, array $filters): Table
    {
        $report = fn () => self::$reports[md5(json_encode($filters))] ??= app(BookingsReport::class)($filters);
        $rows = fn () => collect($report()[$section]['rows'])->keyBy('id')->all();
        $totals = fn () => $report()[$section]['totals'] ?? [];
        $date = fn (string $name, string $label) => TextColumn::make($name)->label($label)->date('d/m/y');
        $status = TextColumn::make('status')->label('Status')->formatStateUsing(fn (?string $state) => Embarque::STATUSES[$state] ?? $state);
        $booking = TextColumn::make('booking')->label('Booking')->url(fn (array $record) => EmbarqueResource::getUrl('view', ['record' => $record['id']]));
        $text = fn (string $name, string $label) => TextColumn::make($name)->label($label)->wrap();
        $quantity = fn (string $name, string $label) => TextColumn::make($name)->label($label)->alignEnd()->placeholder('');
        $number = fn (float $value) => number_format($value, 2, ',', '.');
        $containers = [$quantity('c20', "20'"), $quantity('c40', "40'"), $quantity('roro', 'Roro'), $quantity('tank', 'Tank'), $quantity('outro', 'Outro')];

        $table = $table->records($rows)->emptyStateHeading('Não há registros.');

        return match ($section) {
            'maritime' => $table
                ->heading('Reservas Marítimo')
                ->description(fn () => "Bookings: {$totals()['bookings']} · Containers 20': {$totals()['20']} · 40': {$totals()['40']} · Tank: {$totals()['tank']} · Ro Ro: {$totals()['roro']} · Outros: {$totals()['outro']} · Total: {$totals()['containers']}")
                ->columns([$text('exporter', 'Exportador'), $text('importer', 'Importador'), $booking, $text('carrier', 'Armador'), TextColumn::make('invoice')->label('Fatura'), $text('destination', 'Destino'), ...$containers, $text('ship', 'Navio'), $text('origin', 'Origem'), $date('etd', 'ETD'), $date('exit', 'Saída'), $date('eta', 'ETA'), $date('arrival', 'Chegada'), $status]),
            'lcl' => $table
                ->heading('Reservas LCL')
                ->description(fn () => "Bookings: {$totals()['bookings']} · Total KG: {$number($totals()['kg'])} · Total m³: {$number($totals()['m3'])}")
                ->columns([$text('exporter', 'Exportador'), $text('importer', 'Importador'), $booking, $text('carrier', 'Armador'), TextColumn::make('invoice')->label('Fatura'), $text('destination', 'Destino'), TextColumn::make('weight')->label('LCL')->formatStateUsing(fn ($state, array $record) => $number((float) $state).' /kg · '.$number((float) $record['volume']).' /m3'), $text('origin', 'Origem'), $date('etd', 'ETD'), $date('exit', 'Saída'), $date('eta', 'ETA'), $date('arrival', 'Chegada'), $status]),
            'air' => $table
                ->heading('Reservas Aéreo')
                ->description(fn () => "Bookings: {$totals()['bookings']} · Total KG: {$number($totals()['kg'])} · Total m³: {$number($totals()['m3'])}")
                ->columns([$text('exporter', 'Exportador'), $text('importer', 'Importador'), $booking, TextColumn::make('invoice')->label('Fatura'), $text('destination', 'Destino'), TextColumn::make('weight')->label('Aéreo')->formatStateUsing(fn ($state, array $record) => $number((float) $state).' /kg · '.$number((float) $record['volume']).' /m3'), $text('origin', 'Origem'), $date('etd', 'ETD'), $date('exit', 'Saída'), $date('eta', 'ETA'), $date('arrival', 'Chegada'), $status]),
            'insurance' => $table
                ->heading('Reservas Seguro')
                ->columns([$text('exporter', 'Exportador'), $text('importer', 'Importador'), $booking, TextColumn::make('invoice')->label('Fatura'), $text('origin', 'Origem'), $text('destination', 'Destino'), ...$containers, $date('requested_at', 'Data Solicitação'), $text('broker', 'Despachante'), $text('insurer', 'Cia Seguro'), TextColumn::make('invoice_value')->label('Valor fatura')->alignEnd()->formatStateUsing(fn ($state) => $state === null ? '' : $number((float) $state)), TextColumn::make('freight_value')->label('Valor frete')->alignEnd()->formatStateUsing(fn ($state) => $state === null ? '' : $number((float) $state))]),
            default => $table
                ->heading('Reservas Desembaraço')
                ->columns([$text('exporter', 'Exportador'), $text('importer', 'Importador'), $booking, TextColumn::make('invoice')->label('Fatura'), $date('etd', 'ETD'), $date('eta', 'ETA'), $text('origin', 'Origem'), $text('destination', 'Destino'), $date('requested_at', 'Data Solicitação'), $text('broker', 'Despachante')]),
        };
    }

    /** Uma planilha com as seções escolhidas, uma linha por reserva. */
    private function export()
    {
        $report = app(BookingsReport::class)($this->applied);
        $rows = [];

        foreach (['maritime' => 'Marítimo', 'lcl' => 'LCL', 'air' => 'Aéreo', 'insurance' => 'Seguro', 'clearance' => 'Desembaraço'] as $section => $label) {
            foreach ($report[$section]['rows'] as $row) {
                $rows[] = [$label, $row['exporter'], $row['importer'], $row['booking'], $row['carrier'] ?? null, $row['invoice'], $row['origin'], $row['destination'], $row['ship'] ?? null, $row['c20'] ?? null, $row['c40'] ?? null, $row['roro'] ?? null, $row['tank'] ?? null, $row['outro'] ?? null, $row['weight'] ?? null, $row['volume'] ?? null, $row['etd'] ?? null, $row['exit'] ?? null, $row['eta'] ?? null, $row['arrival'] ?? null, isset($row['status']) ? (Embarque::STATUSES[$row['status']] ?? $row['status']) : null];
            }
        }

        return app(ExportSpreadsheet::class)('Reservas', ['Seção', 'Exportador', 'Importador', 'Booking', 'Armador', 'Fatura', 'Origem', 'Destino', 'Navio', "20'", "40'", 'Roro', 'Tank', 'Outro', 'Kg', 'm3', 'ETD', 'Saída', 'ETA', 'Chegada', 'Status'], $rows);
    }
}
