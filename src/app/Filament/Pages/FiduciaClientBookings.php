<?php

namespace App\Filament\Pages;

use App\Actions\Fiducia\ClientBookingsReport;
use App\Filament\Livewire\ReportResult;
use App\Models\Pessoa;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
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

    /** Filtros do último "Enviar": o resultado fica no componente filho, fora do estado da página. */
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
                Livewire::make(ReportResult::class, fn () => [
                    'action' => ClientBookingsReport::class,
                    'view' => 'filament.pages.fiducia-client-bookings',
                    'arguments' => [$this->applied],
                ])
                    ->key('result'.$this->runs)
                    ->visible(fn () => $this->applied !== null),
            ]);
    }

    public function send(): void
    {
        $this->applied = $this->form->getState();
        $this->runs++;
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
}
