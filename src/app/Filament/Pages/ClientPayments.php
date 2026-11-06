<?php

namespace App\Filament\Pages;

use App\Actions\Financeiro\ClientShipmentCostsPdf;
use App\Actions\Financeiro\ClientShipmentCostsReport;
use App\Models\Armador;
use App\Models\Pessoa;
use App\Services\Cobranca\ChargeDetails;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * reports/relatorioCustosEmbarque (legado), "Consulta Pagamentos Cliente" (menu 6005), no menu Consultas: PDF "Levantamento
 * de Custos de Embarques" do cliente, com as cobranças pagas no período e as ainda abertas.
 */
class ClientPayments extends Page
{
    protected static ?string $title = 'Consulta Pagamentos Cliente';

    protected static ?string $navigationLabel = 'Consulta Pagamentos Cliente';

    protected static ?string $slug = 'financeiro/consulta-pagamentos-cliente';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationParentItem = 'Consultas';

    protected static ?int $navigationSort = 30;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** security.yml do módulo (legado): manager ou financial. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential(['manager', 'financial']);
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
                Select::make('cliente_id')
                    ->label('Cliente')
                    ->required()
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Pessoa::query()
                        ->where(fn ($query) => $query->where('nome', 'like', "%{$search}%")->orWhere('cnpj', 'like', "%{$search}%"))
                        ->orderBy('nome')
                        ->limit(30)
                        ->get()
                        ->mapWithKeys(fn (Pessoa $person) => [$person->getKey() => $person->nome.(filled($person->cnpj) ? " ({$person->cnpj})" : '')]))
                    ->getOptionLabelUsing(fn ($value) => Pessoa::query()->whereKey($value)->value('nome')),
                Grid::make(['default' => 1, 'md' => 2])
                    ->schema([
                        DatePicker::make('data_ini')
                            ->label('Data inicial')
                            ->required(),
                        DatePicker::make('data_fim')
                            ->label('Data final')
                            ->required()
                            ->afterOrEqual('data_ini'),
                        $this->portSelect('porto_origem_id', 'Origem'),
                        $this->portSelect('porto_destino_id', 'Destino'),
                        Select::make('armador_id')
                            ->label('Armador')
                            ->searchable()
                            ->options(fn () => Armador::query()->orderBy('nome')->pluck('nome', 'armador_id')),
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
                                ->action(fn () => $this->form->fill()),
                        ]),
                    ]),
            ]);
    }

    public function send(): ?StreamedResponse
    {
        $state = $this->form->getState();
        $report = app(ClientShipmentCostsReport::class)($state);

        if ($report['shipments'] === []) {
            Notification::make()
                ->title('Nenhuma cobrança encontrada para os filtros informados.')
                ->warning()
                ->send();

            return null;
        }

        return app(ClientShipmentCostsPdf::class)($report, $state['data_ini'], $state['data_fim']);
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
