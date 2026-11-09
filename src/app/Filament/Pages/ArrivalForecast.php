<?php

namespace App\Filament\Pages;

use App\Actions\Financeiro\ArrivalForecastReport;
use App\Filament\Livewire\ReportResult;
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
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
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

    /** Filtros do último "Enviar": o resultado fica no componente filho, fora do estado da página. */
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
                Livewire::make(ReportResult::class, fn () => [
                    'action' => ArrivalForecastReport::class,
                    'view' => 'filament.pages.arrival-forecast',
                    'arguments' => [$this->applied['tipo'], $this->applied['data_ini'] ?? null, $this->applied['data_fim'] ?? null],
                    'viewData' => ['type' => $this->applied['tipo']],
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
}
