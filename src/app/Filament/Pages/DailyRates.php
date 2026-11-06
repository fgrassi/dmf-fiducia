<?php

namespace App\Filament\Pages;

use App\Actions\Financeiro\DailyRatesReport;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
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
 * cotacao/index (legado), "Consulta Cotação Diária" (menu 308), no menu Consultas: valor de cada cotação e taxa de armador por dia, no período.
 */
class DailyRates extends Page
{
    protected static ?string $title = 'Consulta Cotação Diária';

    protected static ?string $navigationLabel = 'Consulta Cotação Diária';

    protected static ?string $slug = 'financeiro/consulta-cotacao-diaria';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationParentItem = 'Consultas';

    protected static ?int $navigationSort = 10;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var array<string, mixed>|null */
    public ?array $report = null;

    /** security.yml do módulo (legado): credencial financial. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('financial');
    }

    public function mount(): void
    {
        $this->form->fill($this->defaults());
        $this->send();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Grid::make(['default' => 1, 'md' => 2])
                    ->schema([
                        DatePicker::make('data_ini')
                            ->label('Data inicial')
                            ->required(),
                        DatePicker::make('data_fim')
                            ->label('Data final')
                            ->required()
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
                                ->label('Enviar')
                                ->submit('send'),
                            Action::make('clear')
                                ->label('Limpar')
                                ->color('gray')
                                ->action(fn () => $this->clear()),
                        ]),
                    ]),
                View::make('filament.pages.daily-rates')
                    ->viewData(fn () => ['report' => $this->report]),
            ]);
    }

    public function send(): void
    {
        $this->report = app(DailyRatesReport::class)($this->form->getState());
    }

    public function clear(): void
    {
        $this->form->fill($this->defaults());
        $this->send();
    }

    /** @return array<string, string> como o legado: de um mês atrás até hoje, já consultado ao abrir */
    private function defaults(): array
    {
        return ['data_ini' => now()->subMonthNoOverflow()->toDateString(), 'data_fim' => now()->toDateString()];
    }
}
