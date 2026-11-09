<?php

namespace App\Filament\Pages;

use App\Actions\Financeiro\DailyRatesReport;
use App\Filament\Livewire\ReportResult;
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

    /** Filtros do último "Enviar": o resultado fica no componente filho, fora do estado da página. */
    public ?array $applied = null;

    /** Muda a cada "Enviar", para o resultado ser refeito mesmo com os mesmos filtros. */
    public int $runs = 0;

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
                Livewire::make(ReportResult::class, fn () => [
                    'action' => DailyRatesReport::class,
                    'view' => 'filament.pages.daily-rates',
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
        $this->send();
    }

    /** @return array<string, string> como o legado: de um mês atrás até hoje, já consultado ao abrir */
    private function defaults(): array
    {
        return ['data_ini' => now()->subMonthNoOverflow()->toDateString(), 'data_fim' => now()->toDateString()];
    }
}
