<?php

namespace App\Filament\Pages;

use App\Actions\Financeiro\ArrivalForecastReport;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\View;
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

    /** @var array{sea: list<array<string, mixed>>, other: list<array<string, mixed>>}|null */
    public ?array $report = null;

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
                View::make('filament.pages.arrival-forecast')
                    ->viewData(fn () => ['report' => $this->report, 'type' => $this->data['tipo'] ?? 'i']),
            ]);
    }

    public function send(): void
    {
        $filters = $this->form->getState();

        $this->report = app(ArrivalForecastReport::class)($filters['tipo'], $filters['data_ini'] ?? null, $filters['data_fim'] ?? null);
    }
}
