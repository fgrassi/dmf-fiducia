<?php

namespace App\Filament\Pages;

use App\Filament\Livewire\ReportSectionTable;
use App\Filament\Support\DashboardPeriod;
use App\Filament\Widgets\BookingsByModal;
use App\Filament\Widgets\BookingsPerMonth;
use App\Filament\Widgets\FinanceStats;
use App\Filament\Widgets\NextMovements;
use App\Filament\Widgets\OperationStats;
use App\Filament\Widgets\OverdueCharges;
use App\Filament\Widgets\TopClients;
use Filament\Actions\Action;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;

/** Dashboard do painel: o título leva o ano do corte e o subtítulo a data de corte e a data de hoje. */
class Dashboard extends BaseDashboard
{
    public function getTitle(): string
    {
        return 'Dashboard '.DashboardPeriod::year();
    }

    public function getSubheading(): string
    {
        $only = ['i' => ' · só importação', 'e' => ' · só exportação'][auth()->user()?->onlyOperation()] ?? '';

        return 'Dados cadastrados desde '.DashboardPeriod::sinceLabel().' · hoje, '.today()->format('d/m/Y').$only;
    }

    /** Seções do dashboard, que podem ser recolhidas (o estado fica salvo no navegador); a ordem dos widgets é a do `$sort`. */
    private const SECTIONS = [
        'Movimento diário' => [DailyMovement::class],
        'Operações' => [OperationStats::class, BookingsPerMonth::class, BookingsByModal::class, NextMovements::class, TopClients::class],
        'Financeiro' => [FinanceStats::class, OverdueCharges::class],
    ];

    public function getWidgetsContentComponent(): Component
    {
        $sections = [];

        foreach (self::SECTIONS as $heading => $group) {
            $daily = $group === [DailyMovement::class];
            $components = $daily
                ? $this->dailyMovementComponents()
                : $this->getWidgetsSchemaComponents(array_values(array_intersect($this->getWidgets(), $group)));

            if ($components !== []) {
                $sections[] = Section::make($heading)
                    ->description($daily ? 'Hoje, '.today()->format('d/m/Y') : null)
                    ->headerActions($daily ? [
                        Action::make('abrirMovimentoDiario')
                            ->label('Abrir relatório')
                            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                            ->url(DailyMovement::getUrl()),
                    ] : [])
                    ->schema($components)
                    ->columns($this->getColumns())
                    ->collapsible()
                    ->persistCollapsed()
                    ->id(str($heading)->slug()->toString());
            }
        }

        return Section::make()->schema($sections)->contained(false);
    }

    /**
     * As duas listas do relatório Movimento Diário (reservas e as cotações Expo, Impo e Impo Aéreo juntas) só de hoje, na operação do usuário.
     *
     * @return list<Livewire>
     */
    private function dailyMovementComponents(): array
    {
        if (! DailyMovement::canAccess()) {
            return [];
        }

        $filters = ['data_ini' => today()->toDateString(), 'data_fim' => today()->toDateString(), 'impor_expor' => auth()->user()->onlyOperation()];

        return array_map(
            fn (string $section) => Livewire::make(ReportSectionTable::class, ['page' => DailyMovement::class, 'section' => $section, 'filters' => $filters])
                ->key('daily-movement-'.$section)
                ->columnSpanFull(),
            ['bookings', 'quotes'],
        );
    }
}
