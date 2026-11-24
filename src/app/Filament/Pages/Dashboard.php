<?php

namespace App\Filament\Pages;

use App\Filament\Support\DashboardPeriod;
use App\Filament\Widgets\Birthdays;
use App\Filament\Widgets\BookingsByModal;
use App\Filament\Widgets\BookingsPerMonth;
use App\Filament\Widgets\FinanceStats;
use App\Filament\Widgets\NextArrivals;
use App\Filament\Widgets\NextDepartures;
use App\Filament\Widgets\OperationStats;
use App\Filament\Widgets\OverdueCharges;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;

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
        'Operações' => [OperationStats::class, BookingsPerMonth::class, BookingsByModal::class, NextDepartures::class, NextArrivals::class],
        'Financeiro' => [FinanceStats::class, OverdueCharges::class],
        'Aniversários' => [Birthdays::class],
    ];

    public function getWidgetsContentComponent(): Component
    {
        $sections = [];

        foreach (self::SECTIONS as $heading => $group) {
            $widgets = $this->getWidgetsSchemaComponents(array_values(array_intersect($this->getWidgets(), $group)));

            if ($widgets !== []) {
                $sections[] = Section::make($heading)
                    ->schema($widgets)
                    ->columns($this->getColumns())
                    ->collapsible()
                    ->persistCollapsed()
                    ->id(str($heading)->slug()->toString());
            }
        }

        return Section::make()->schema($sections)->contained(false);
    }
}
