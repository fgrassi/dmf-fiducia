<?php

namespace App\Filament\Pages;

use App\Actions\AccountingCovers\CoverResultReport as Report;
use App\Actions\AccountingCovers\ExportCoverResultReport;
use App\Models\CapaProcessoArquivo;
use App\Models\Embarque;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/** capasContabilidade/relatorio (legado): resultado das capas por filtro, com agrupamento, resumos por despesa e escritório e ranking de clientes. */
class CoverResultReport extends Page
{
    protected static ?string $title = 'Relatório de Resultado das Capas';

    protected static ?string $navigationLabel = 'Resultado das Capas';

    protected static ?string $slug = 'financeiro/relatorios/resultado-das-capas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationParentItem = 'Relatórios';

    protected static ?int $navigationSort = 10;

    public ?array $data = [];

    /** Filtros do último "Buscar": o relatório só muda quando o botão é usado, não a cada campo mexido. */
    public ?array $applied = null;

    /** security.yml + executeRelatorio (legado): `director`, ou `financial` junto com `manager` ou `supervisor`. */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->hasLegacyCredential('director') || ($user?->hasLegacyCredential('financial') && $user->hasLegacyCredential(['manager', 'supervisor'])));
    }

    public function mount(): void
    {
        $this->form->fill(['show_list' => true, 'show_ranking' => false, 'group_by' => [], 'categories' => []]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Grid::make(['default' => 1, 'md' => 2, 'xl' => 4])
                    ->schema([
                        Select::make('booking_id')
                            ->label('Reserva')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search) => DB::table('embarque')->where('booking', 'like', "%{$search}%")->orderBy('booking')->limit(30)->pluck('booking', 'embarque_id')->all())
                            ->getOptionLabelUsing(fn ($value) => DB::table('embarque')->where('embarque_id', $value)->value('booking')),
                        $this->lookup('client_id', 'Cliente', 'pessoa', 'pessoa_id'),
                        TextInput::make('commodity')
                            ->label('Mercadoria'),
                        $this->lookup('agent_id', 'Agente', 'pessoa', 'pessoa_id'),
                        Select::make('operation')
                            ->label('Impo/Expo')
                            ->options(Embarque::OPERATIONS),
                        $this->lookup('origin_country_id', 'País de origem', 'paises', 'paises_id'),
                        $this->lookup('origin_port_id', 'Porto de origem', 'porto', 'porto_id'),
                        TextInput::make('house_bl')
                            ->label('HBL'),
                        Select::make('type')
                            ->label('Tipo')
                            ->options(['m' => Embarque::TYPES['m'], 'a' => Embarque::TYPES['a'], 's' => Embarque::TYPES['s']]),
                        $this->lookup('destination_country_id', 'País de destino', 'paises', 'paises_id'),
                        $this->lookup('destination_port_id', 'Porto de destino', 'porto', 'porto_id'),
                        Select::make('folder_id')
                            ->label('Caixa')
                            ->options(fn () => CapaProcessoArquivo::orderByDesc('ano')->orderByDesc('mes')->pluck('nome', 'capa_processo_arquivo_id')->all()),
                        $this->lookup('carrier_id', 'Armador', 'armador', 'armador_id'),
                        $this->lookup('airline_id', 'Cia aérea', 'cia_aerea', 'id', 'name'),
                        $this->lookup('seller_id', 'Vendedor', 'vendedor', 'vendedor_id'),
                        DatePicker::make('from')
                            ->label('Pagamento de'),
                        DatePicker::make('until')
                            ->label('Pagamento até')
                            ->afterOrEqual('from'),
                    ]),
                Select::make('group_by')
                    ->label('Agrupar por (na ordem escolhida)')
                    ->multiple()
                    ->options(Report::GROUPS),
                CheckboxList::make('categories')
                    ->label('Somente')
                    ->options(array_combine(array_keys(Report::CATEGORIES), array_keys(Report::CATEGORIES)))
                    ->columns(4),
                Grid::make(['default' => 1, 'md' => 2])
                    ->schema([
                        Checkbox::make('show_list')
                            ->label('Exibir lista de capas'),
                        Checkbox::make('show_ranking')
                            ->label('Exibir ranking de clientes'),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->livewireSubmitHandler('search')
                    ->footer([
                        Actions::make([
                            Action::make('search')
                                ->label('Buscar')
                                ->submit('search'),
                        ]),
                    ]),
                View::make('filament.pages.cover-result-report')
                    ->viewData(fn () => ['report' => $this->report(), 'options' => $this->applied]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Exportar para Excel')
                ->icon(Heroicon::OutlinedTableCells)
                ->visible(fn () => $this->applied !== null && ($this->applied['show_list'] ?? false))
                ->action(fn () => app(ExportCoverResultReport::class)($this->report())),
        ];
    }

    /** @return array<string, mixed>|null null até o primeiro "Buscar" */
    private function report(): ?array
    {
        return $this->applied === null ? null : app(Report::class)($this->applied, $this->applied['group_by'] ?? [], $this->applied['categories'] ?? []);
    }

    public function search(): void
    {
        $state = $this->form->getState();

        if (! Report::hasFilter($state)) {
            Notification::make()->title('Informe ao menos um filtro.')->warning()->send();

            return;
        }

        $this->applied = $state;
    }

    private function lookup(string $name, string $label, string $table, string $key, string $column = 'nome'): Select
    {
        return Select::make($name)
            ->label($label)
            ->searchable()
            ->getSearchResultsUsing(fn (string $search) => DB::table($table)->where($column, 'like', "%{$search}%")->orderBy($column)->limit(30)->pluck($column, $key)->all())
            ->getOptionLabelUsing(fn ($value) => DB::table($table)->where($key, $value)->value($column));
    }
}
