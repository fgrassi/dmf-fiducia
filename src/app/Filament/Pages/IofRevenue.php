<?php

namespace App\Filament\Pages;

use App\Actions\Financeiro\IofRevenueReport;
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
 * relatorioIof (legado), "Arrecadação IOF": IOF recebido por reserva e HBL, pela data de recebimento da cobrança. Fica no
 * menu Relatórios.
 */
class IofRevenue extends Page
{
    protected static ?string $title = 'Arrecadação IOF';

    protected static ?string $navigationLabel = 'Arrecadação IOF';

    protected static ?string $slug = 'financeiro/relatorios/arrecadacao-iof';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationParentItem = 'Relatórios';

    protected static ?int $navigationSort = 18;

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
                View::make('filament.pages.iof-revenue')
                    ->viewData(fn () => ['report' => $this->report]),
            ]);
    }

    public function send(): void
    {
        $this->report = app(IofRevenueReport::class)($this->form->getState());
    }

    public function clear(): void
    {
        $this->form->fill($this->defaults());
        $this->report = null;
    }

    /** @return array<string, string> o mês corrente */
    private function defaults(): array
    {
        return ['data_ini' => now()->startOfMonth()->toDateString(), 'data_fim' => now()->endOfMonth()->toDateString()];
    }
}
